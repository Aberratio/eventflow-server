<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use ZmierzymyCzas\Domain\Events\EventTeamRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Integration\Http\Rbac\RbacFixtures;
use ZmierzymyCzas\Tests\Support\HttpTestCase;
use ZmierzymyCzas\Tests\Support\SavepointPdo;

/**
 * R-01 (S2): inviting Co-managers by e-mail, accepting the invitation, resending and revoking. Runs on the
 * RBAC fixture world (the Owner is `organizer`); every test is rolled back to a savepoint.
 */
final class EventTeamInvitationContractTest extends HttpTestCase
{
    private static ?SavepointPdo $sharedPdo = null;
    private static RbacFixtures $fx;

    public static function setUpBeforeClass(): void
    {
        [self::$sharedPdo, self::$fx] = SavepointPdo::openWorld(RbacFixtures::build(...));
    }

    public static function tearDownAfterClass(): void
    {
        self::$sharedPdo?->rollbackOuter();
        self::$sharedPdo = null;
    }

    protected function createPdo(): \PDO
    {
        return self::$sharedPdo ?? throw new \LogicException('The fixture world is not open.');
    }

    protected function setUp(): void
    {
        // Set before the app is built: the mailer config is read at route registration. The base URL is
        // unusable on purpose - the row lands in the outbox, the first send fails and is retried later.
        Env::set('CENTRAL_MAILER_API_KEY', 'phpunit-key');
        Env::set('CENTRAL_MAILER_BASE_URL', 'unusable://mailer.invalid');
        Env::set('APP_URL', 'https://app.example.test');
        parent::setUp();
        self::$sharedPdo->savepoint('team_invite_test');
    }

    protected function tearDown(): void
    {
        self::$sharedPdo->rollbackTo('team_invite_test');
        Env::restore('CENTRAL_MAILER_API_KEY');
        Env::restore('CENTRAL_MAILER_BASE_URL');
        Env::restore('APP_URL');
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function call(?string $actor, string $method, string $path, array $body = []): array
    {
        $headers = [];
        if ($actor !== null) {
            $identity = self::$fx->actors[$actor];
            $headers = $this->bearer($identity['id'], $identity['role'], $identity['email']);
        }
        $response = $this->request($method, $path, $body, $headers);

        return ['status' => $response->getStatusCode(), 'json' => json_decode((string) $response->getBody(), true) ?? []];
    }

    private function team(string $suffix = ''): string
    {
        return '/api/v1/events/' . self::$fx->values['event'] . '/team' . $suffix;
    }

    /** @return list<array<string, mixed>> */
    private function outbox(string $email, string $type): array
    {
        $stmt = self::$sharedPdo->prepare('SELECT source_entity_type, html FROM central_mailer_outbox WHERE recipient = :email AND source_entity_type = :type ORDER BY created_at ASC, id ASC');
        $stmt->execute(['email' => $email, 'type' => $type]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function tokenFromLastInvitation(string $email): string
    {
        $rows = $this->outbox($email, 'event_team_invitation');
        self::assertNotSame([], $rows, 'the invitation mail is queued');
        self::assertSame(1, preg_match('#/team-invitations/([a-f0-9]{64})#', (string) end($rows)['html'], $m));

        return $m[1];
    }

    private function activityCount(string $action): int
    {
        $stmt = self::$sharedPdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE action = :action AND target_id = :eventId');
        $stmt->execute(['action' => $action, 'eventId' => self::$fx->values['event']]);

        return (int) $stmt->fetchColumn();
    }

    private function invite(string $email): array
    {
        return $this->call('organizer', 'POST', $this->team('/invitations'), ['email' => $email]);
    }

    public function testFullFlowInviteAcceptAndManage(): void
    {
        $invitee = 'foreignOrganizer';
        $email = self::$fx->actors[$invitee]['email'];

        self::assertSame(403, $this->call($invitee, 'PATCH', '/api/v1/events/' . self::$fx->values['event'], ['name' => 'before'])['status']);

        $invited = $this->invite(strtoupper($email));
        self::assertSame(201, $invited['status']);
        self::assertSame('invited', $invited['json']['data']['status']);
        self::assertSame($email, $invited['json']['data']['email'], 'the address is stored lower-cased');
        self::assertArrayNotHasKey('inviteTokenHash', $invited['json']['data']);
        self::assertArrayNotHasKey('token', $invited['json']['data']);
        self::assertSame(1, $this->activityCount('event_team_invited'));

        $token = $this->tokenFromLastInvitation($email);
        $stored = self::$sharedPdo->prepare('SELECT invite_token_hash FROM event_team_members WHERE id = :id');
        $stored->execute(['id' => $invited['json']['data']['id']]);
        self::assertSame(hash('sha256', $token), $stored->fetchColumn(), 'only the hash is stored');

        // Still invited: nothing is granted.
        self::assertSame(403, $this->call($invitee, 'PATCH', '/api/v1/events/' . self::$fx->values['event'], ['name' => 'too early'])['status']);

        $preview = $this->call(null, 'GET', '/api/v1/team-invitations/' . $token);
        self::assertSame(200, $preview['status']);
        self::assertSame(self::$fx->prefix . ' event', $preview['json']['data']['eventName']);
        self::assertSame('Rbac organizer', $preview['json']['data']['inviterName']);
        self::assertStringNotContainsString($email, json_encode($preview['json']), 'the preview hides the address');

        $accepted = $this->call($invitee, 'POST', '/api/v1/team-invitations/' . $token . '/accept');
        self::assertSame(200, $accepted['status']);
        self::assertSame('active', $accepted['json']['data']['status']);
        self::assertSame('co_manager', $accepted['json']['data']['teamRole']);
        self::assertSame(1, $this->activityCount('event_team_joined'));

        self::assertSame(200, $this->call($invitee, 'PATCH', '/api/v1/events/' . self::$fx->values['event'], ['name' => 'after'])['status']);
        // ...but still no owner-level rights.
        self::assertSame(403, $this->call($invitee, 'DELETE', '/api/v1/events/' . self::$fx->values['event'])['status']);
        self::assertSame(403, $this->call($invitee, 'GET', $this->team())['status']);

        // Accepting again is a harmless repeat and is not logged twice.
        self::assertSame(200, $this->call($invitee, 'POST', '/api/v1/team-invitations/' . $token . '/accept')['status']);
        self::assertSame(1, $this->activityCount('event_team_joined'));

        $roster = $this->call('organizer', 'GET', $this->team());
        self::assertSame(200, $roster['status']);
        self::assertSame(self::$fx->values['organizer'], $roster['json']['data']['owner']['id']);
        $byEmail = array_column($roster['json']['data']['members'], null, 'email');
        self::assertSame('active', $byEmail[$email]['status']);
        self::assertArrayNotHasKey('inviteTokenHash', $byEmail[$email]);
    }

    public function testAnInvitationForAnAddressWithoutAccountIsAcceptedAfterSignUp(): void
    {
        $email = self::$fx->prefix . '-newcomer@example.test';
        self::assertSame(201, $this->invite($email)['status']);
        $token = $this->tokenFromLastInvitation($email);

        $user = (new UserRepository(self::$sharedPdo))->create(['name' => 'Newcomer', 'email' => $email, 'password' => 'Haslo-123456', 'role' => 'participant']);
        $headers = $this->bearer((string) $user['id'], 'participant', $email);
        $response = $this->request('POST', '/api/v1/team-invitations/' . $token . '/accept', [], $headers);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((new EventTeamRepository(self::$sharedPdo))->isActiveCoManager(self::$fx->values['event'], (string) $user['id']));
    }

    public function testAcceptingWithAnotherAccountIsForbidden(): void
    {
        $email = self::$fx->actors['foreignOrganizer']['email'];
        self::assertSame(201, $this->invite($email)['status']);
        $token = $this->tokenFromLastInvitation($email);

        $wrong = $this->call('coManagerOrg', 'POST', '/api/v1/team-invitations/' . $token . '/accept');
        self::assertSame(403, $wrong['status']);
        self::assertSame('invitation_email_mismatch', $wrong['json']['error']['code']);
        self::assertSame(401, $this->call(null, 'POST', '/api/v1/team-invitations/' . $token . '/accept')['status']);

        // The right account can still accept: the failed attempts changed nothing.
        self::assertSame(200, $this->call('foreignOrganizer', 'POST', '/api/v1/team-invitations/' . $token . '/accept')['status']);
        // After activation another account is still refused.
        self::assertSame(403, $this->call('coManagerOrg', 'POST', '/api/v1/team-invitations/' . $token . '/accept')['status']);
    }

    public function testExpiredInvitationIsRefused(): void
    {
        $email = self::$fx->actors['foreignOrganizer']['email'];
        $created = $this->invite($email);
        $token = $this->tokenFromLastInvitation($email);
        self::$sharedPdo->prepare('UPDATE event_team_members SET invite_expires_at = :past WHERE id = :id')
            ->execute(['past' => date('Y-m-d H:i:s', time() - 60), 'id' => $created['json']['data']['id']]);

        self::assertSame(410, $this->call(null, 'GET', '/api/v1/team-invitations/' . $token)['status']);
        $accept = $this->call('foreignOrganizer', 'POST', '/api/v1/team-invitations/' . $token . '/accept');
        self::assertSame(410, $accept['status']);
        self::assertSame('invitation_expired', $accept['json']['error']['code']);
        self::assertSame(403, $this->call('foreignOrganizer', 'PATCH', '/api/v1/events/' . self::$fx->values['event'], ['name' => 'nope'])['status']);

        // A resend issues a fresh 7-day token and the old one stays dead.
        $resent = $this->call('organizer', 'POST', $this->team('/' . $created['json']['data']['id'] . '/resend'));
        self::assertSame(200, $resent['status']);
        $fresh = $this->tokenFromLastInvitation($email);
        self::assertNotSame($token, $fresh);
        self::assertSame(404, $this->call(null, 'GET', '/api/v1/team-invitations/' . $token)['status']);
        self::assertSame(200, $this->call('foreignOrganizer', 'POST', '/api/v1/team-invitations/' . $fresh . '/accept')['status']);
        self::assertCount(2, $this->outbox($email, 'event_team_invitation'));
    }

    public function testRevokeTakesTheAccessAwayAndNotifies(): void
    {
        $actor = 'coManager';
        $email = self::$fx->actors[$actor]['email'];
        $memberId = (string) array_column($this->call('organizer', 'GET', $this->team())['json']['data']['members'], 'id', 'email')[$email];

        self::assertSame(200, $this->call($actor, 'PATCH', '/api/v1/events/' . self::$fx->values['event'], ['name' => 'while active'])['status']);

        $revoked = $this->call('organizer', 'DELETE', $this->team('/' . $memberId));
        self::assertSame(200, $revoked['status']);
        self::assertSame('revoked', $revoked['json']['data']['status']);
        self::assertSame(1, $this->activityCount('event_team_revoked'));
        self::assertCount(1, $this->outbox($email, 'event_team_revoked'));

        self::assertSame(403, $this->call($actor, 'PATCH', '/api/v1/events/' . self::$fx->values['event'], ['name' => 'after revoke'])['status']);
        self::assertSame(403, $this->call($actor, 'GET', '/api/v1/events/' . self::$fx->values['event'] . '/registrations')['status']);

        // Revoking again is idempotent and sends nothing more.
        self::assertSame(200, $this->call('organizer', 'DELETE', $this->team('/' . $memberId))['status']);
        self::assertCount(1, $this->outbox($email, 'event_team_revoked'));

        // The Owner can invite the same address again; the old row is reused and works again.
        self::assertSame(201, $this->invite($email)['status']);
        $token = $this->tokenFromLastInvitation($email);
        self::assertSame(200, $this->call($actor, 'POST', '/api/v1/team-invitations/' . $token . '/accept')['status']);
        self::assertSame(200, $this->call($actor, 'PATCH', '/api/v1/events/' . self::$fx->values['event'], ['name' => 'back again'])['status']);
    }

    public function testRevokingAPendingInvitationKillsItsLink(): void
    {
        $email = self::$fx->actors['foreignOrganizer']['email'];
        $created = $this->invite($email);
        $token = $this->tokenFromLastInvitation($email);

        self::assertSame(200, $this->call('organizer', 'DELETE', $this->team('/' . $created['json']['data']['id']))['status']);
        self::assertSame([], $this->outbox($email, 'event_team_revoked'), 'no "access taken away" mail for a never-accepted invitation');
        self::assertSame(404, $this->call(null, 'GET', '/api/v1/team-invitations/' . $token)['status']);
        self::assertSame(404, $this->call('foreignOrganizer', 'POST', '/api/v1/team-invitations/' . $token . '/accept')['status']);
    }

    public function testInvitingValidatesTheAddress(): void
    {
        self::assertSame(422, $this->invite('not-an-email')['status']);
        self::assertSame(422, $this->call('organizer', 'POST', $this->team('/invitations'))['status']);
        $owner = $this->invite(self::$fx->actors['organizer']['email']);
        self::assertSame(409, $owner['status']);
        self::assertSame('team_member_owner', $owner['json']['error']['code']);
        $active = $this->invite(self::$fx->actors['coManager']['email']);
        self::assertSame(409, $active['status']);
        self::assertSame('team_member_active', $active['json']['error']['code']);
    }

    public function testUnknownMemberOrTokenIs404(): void
    {
        self::assertSame(404, $this->call('organizer', 'DELETE', $this->team('/no-such-member'))['status']);
        self::assertSame(404, $this->call('organizer', 'POST', $this->team('/no-such-member/resend'))['status']);
        self::assertSame(404, $this->call(null, 'GET', '/api/v1/team-invitations/' . str_repeat('a', 64))['status']);
        self::assertSame(404, $this->call(null, 'GET', '/api/v1/team-invitations/short')['status']);
    }

    public function testAMemberOfAnotherEventCannotBeTouchedThroughThisOne(): void
    {
        $draftTeam = (new EventTeamRepository(self::$sharedPdo))->listForEvent(self::$fx->values['eventDraft']);
        self::assertNotSame([], $draftTeam);
        $foreignRow = (string) $draftTeam[0]['id'];

        self::assertSame(404, $this->call('organizer', 'DELETE', $this->team('/' . $foreignRow))['status']);
        self::assertSame(404, $this->call('organizer', 'POST', $this->team('/' . $foreignRow . '/resend'))['status']);
    }

    /** @return array<string, array{string}> */
    public static function nonOwners(): array
    {
        return [
            'co-manager (participant)' => ['coManager'],
            'co-manager (organizer)' => ['coManagerOrg'],
            'revoked' => ['revoked'],
            'stranger organizer' => ['foreignOrganizer'],
            'moderator' => ['moderator'],
        ];
    }

    #[DataProvider('nonOwners')]
    public function testOnlyTheOwnerManagesTheRoster(string $actor): void
    {
        self::assertSame(403, $this->call($actor, 'GET', $this->team())['status']);
        self::assertSame(403, $this->call($actor, 'POST', $this->team('/invitations'), ['email' => 'someone@example.test'])['status']);
        self::assertSame(403, $this->call($actor, 'DELETE', $this->team('/' . self::$fx->values['teamMemberRow']))['status']);
        self::assertSame(403, $this->call($actor, 'POST', $this->team('/' . self::$fx->values['teamMemberRow'] . '/resend'))['status']);
    }

    public function testAdminHasOwnerRights(): void
    {
        self::assertSame(200, $this->call('admin', 'GET', $this->team())['status']);
        self::assertSame(201, $this->call('admin', 'POST', $this->team('/invitations'), ['email' => self::$fx->prefix . '-by-admin@example.test'])['status']);
    }
}
