<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Events\EventTeamRepository;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\MailRecipientMask;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Http\Access\EventAccess;
use ZmierzymyCzas\Http\DomainErrorResponder;
use ZmierzymyCzas\Http\Support\ActivityLogger;
use ZmierzymyCzas\Http\Support\RequestInput;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\JsonResponder;

/**
 * An event's Team roster: the owner invites, re-invites and revokes co-managers; the invitee
 * previews and accepts the invitation by its token. Roster changes are owner-level.
 */
final class EventTeamController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly UserRepository $users,
        private readonly EventTeamRepository $team,
        private readonly EventAccess $eventAccess,
        private readonly ActivityLogger $activity,
        private readonly CriticalMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        $event = $this->events->find($args['id']);
        if (!$this->eventAccess->canManage($event, RequestInput::auth($request), true)) {
            return JsonResponder::forbidden($response);
        }
        $owner = $this->users->find((string) $event['organizerId']);

        return JsonResponder::ok($response, [
            'owner' => $owner === null ? null : ['id' => $owner['id'], 'name' => $owner['name'], 'email' => $owner['email']],
            'members' => $this->team->listForEvent((string) $args['id']),
        ]);
    }

    /** @param array<string, string> $args */
    public function invite(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $event = $this->events->find($args['id']);
        if (!$this->eventAccess->canManage($event, $auth, true)) {
            return JsonResponder::forbidden($response);
        }
        $email = strtolower(trim((string) (RequestInput::body($request)['email'] ?? '')));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return JsonResponder::error($response, 'validation_error', 'Podaj poprawny adres e-mail.', 422, ['field' => 'email']);
        }
        $owner = $this->users->find((string) $event['organizerId']);
        if ($owner !== null && strtolower((string) $owner['email']) === $email) {
            return JsonResponder::error($response, 'team_member_owner', 'Właściciel wydarzenia jest już w zespole.', 409);
        }
        try {
            $issued = $this->team->invite((string) $event['id'], $email, (string) ($auth['sub'] ?? ''));
        } catch (RuntimeException $e) {
            return DomainErrorResponder::respond($response, $e, [
                'team_member_active' => 'Ta osoba jest już współzarządzającym tego wydarzenia.',
            ]);
        }
        $mailState = $this->sendInvitation($event, $issued['member'], $issued['token'], $this->inviterName($auth));
        $this->activity->log($auth, 'event_team_invited', 'Zaproszono współzarządzającego do wydarzenia', 'event', (string) $event['id'], (string) ($event['name'] ?? ''), ['recipient' => MailRecipientMask::mask($email)]);

        return JsonResponder::ok($response, $issued['member'] + ['mailState' => $mailState], 'Zaproszenie zostało utworzone.', 201);
    }

    /** @param array<string, string> $args */
    public function resend(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $event = $this->events->find($args['id']);
        if (!$this->eventAccess->canManage($event, $auth, true)) {
            return JsonResponder::forbidden($response);
        }
        $member = $this->team->find((string) $args['memberId']);
        if ($member === null || (string) $member['eventId'] !== (string) $event['id']) {
            return JsonResponder::error($response, 'not_found', 'Nie znaleziono członka zespołu.', 404);
        }
        if ($member['status'] !== 'invited') {
            return JsonResponder::error($response, 'invalid_status_transition', 'Zaproszenie można wysłać ponownie tylko do osoby, która go jeszcze nie przyjęła.', 409);
        }
        $issued = $this->team->invite((string) $event['id'], (string) $member['email'], (string) ($auth['sub'] ?? ''));
        $mailState = $this->sendInvitation($event, $issued['member'], $issued['token'], $this->inviterName($auth));
        $this->activity->log($auth, 'event_team_invitation_resent', 'Wysłano ponownie zaproszenie do zespołu wydarzenia', 'event', (string) $event['id'], (string) ($event['name'] ?? ''), ['recipient' => MailRecipientMask::mask((string) $member['email'])]);

        return JsonResponder::ok($response, $issued['member'] + ['mailState' => $mailState], 'Zaproszenie zostało wysłane ponownie.');
    }

    /** @param array<string, string> $args */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $event = $this->events->find($args['id']);
        if (!$this->eventAccess->canManage($event, $auth, true)) {
            return JsonResponder::forbidden($response);
        }
        $member = $this->team->find((string) $args['memberId']);
        if ($member === null || (string) $member['eventId'] !== (string) $event['id']) {
            return JsonResponder::error($response, 'not_found', 'Nie znaleziono członka zespołu.', 404);
        }
        if ($member['status'] !== 'revoked') {
            $this->team->revoke((string) $member['id']);
            $this->activity->log($auth, 'event_team_revoked', 'Odebrano dostęp współzarządzającemu wydarzenia', 'event', (string) $event['id'], (string) ($event['name'] ?? ''), ['recipient' => MailRecipientMask::mask((string) $member['email'])]);
            if ($member['status'] === 'active') {
                try {
                    $this->mailer->sendEventTeamRevoked((string) $member['id'] . ':' . time(), (string) $event['id'], (string) $member['email'], (string) ($event['name'] ?? ''));
                } catch (Throwable $e) {
                    $this->logger->error('Event team revocation mail could not be queued', ['eventId' => (string) $event['id'], 'memberId' => (string) $member['id'], 'errorClass' => $e::class]);
                }
            }
        }

        return JsonResponder::ok($response, $this->team->find((string) $member['id']));
    }

    /**
     * Public preview: the token is the credential; it shows the event and the inviter, never the Team.
     *
     * @param array<string, string> $args
     */
    public function previewInvitation(Request $request, Response $response, array $args): Response
    {
        $invitation = $this->team->findInvitation((string) $args['token']);
        if ($invitation === null || ($invitation['eventDeletedAt'] ?? null) !== null) {
            return JsonResponder::error($response, 'invitation_not_found', 'To zaproszenie nie istnieje albo zostało odwołane.', 404);
        }
        if ($invitation['expired']) {
            return JsonResponder::error($response, 'invitation_expired', 'To zaproszenie wygasło. Poproś organizatora o nowe.', 410);
        }

        return JsonResponder::ok($response, [
            'eventName' => $invitation['eventName'],
            'inviterName' => $invitation['inviterName'] ?? '',
            'email' => MailRecipientMask::mask((string) $invitation['email']),
            'status' => $invitation['status'],
            'expiresAt' => $invitation['inviteExpiresAt'],
        ]);
    }

    /** @param array<string, string> $args */
    public function acceptInvitation(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $user = $this->users->find((string) ($auth['sub'] ?? ''));
        if ($user === null) {
            return JsonResponder::error($response, 'unauthorized', 'Sesja wygasła. Zaloguj się ponownie.', 401);
        }
        $invitation = $this->team->findInvitation((string) $args['token']);
        try {
            $member = $this->team->accept((string) $args['token'], (string) $user['id'], (string) $user['email']);
        } catch (RuntimeException $e) {
            return DomainErrorResponder::respond($response, $e, [
                'invitation_not_found',
                'invitation_expired',
                'invitation_email_mismatch',
                'team_member_owner',
                'team_member_active',
            ]);
        }
        // Only the first acceptance is news for the activity register (a repeat call is a no-op).
        if (($invitation['status'] ?? '') === 'invited') {
            $this->activity->log($auth, 'event_team_joined', 'Przyjęto zaproszenie do zespołu wydarzenia', 'event', (string) $member['eventId'], (string) ($invitation['eventName'] ?? ''));
        }

        return JsonResponder::ok($response, [
            'eventId' => $member['eventId'],
            'eventName' => $invitation['eventName'] ?? '',
            'teamRole' => 'co_manager',
            'status' => $member['status'],
        ], 'Zaproszenie zostało przyjęte.');
    }

    /** @param array<string, mixed> $auth */
    private function inviterName(array $auth): string
    {
        $inviter = $this->users->find((string) ($auth['sub'] ?? ''));
        return (string) ($inviter['name'] ?? '');
    }

    /**
     * Queues the invitation mail and reports what happened to it: 'sent' (Central Mailer accepted it),
     * 'pending' (a retry is scheduled) or 'lost' (nothing will be sent).
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $member
     */
    private function sendInvitation(array $event, array $member, string $token, string $inviterName): string
    {
        $acceptLink = rtrim((string) Env::get('APP_URL', ''), '/') . '/team-invitations/' . $token;
        $state = 'lost';
        try {
            $row = $this->mailer->sendEventTeamInvitation(
                (string) $member['id'] . ':' . substr($token, 0, 12),
                (string) $event['id'],
                (string) $member['email'],
                (string) ($event['name'] ?? ''),
                $inviterName,
                $acceptLink,
                EventTeamRepository::INVITE_TTL_DAYS
            );
            if ($row !== null) {
                if (($row['centralMailerEmailId'] ?? null) !== null) {
                    $state = 'sent';
                } elseif (($row['nextAttemptAt'] ?? null) !== null) {
                    $state = 'pending';
                }
            }
        } catch (Throwable $e) {
            // Ids and the error class only, never the link, the token or the address.
            $this->logger->error('Event team invitation mail could not be queued', ['eventId' => (string) $event['id'], 'memberId' => (string) $member['id'], 'errorClass' => $e::class]);
        }

        return $state;
    }
}
