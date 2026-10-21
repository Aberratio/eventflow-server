<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Template preview (POST …/mail-templates/{mailType}/preview, global and
 * per event) and the read-only mail log (GET /admin/mail-log, GET /events/{id}/mail-log) — response shapes
 * and RBAC, through the real routes.
 */
final class MailTemplatePreviewAndLogContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private CentralMailerOutboxRepository $outbox;
    private string $prefix;
    private array $admin;
    private array $moderator;
    private array $organizer;
    private array $stranger;
    private array $participant;
    private string $eventId;
    private string $otherEventId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->outbox = new CentralMailerOutboxRepository($this->pdo);
        $this->prefix = 'mlog-' . bin2hex(random_bytes(4));

        $make = fn (string $role, string $name) => $this->users->create([
            'name' => 'Mail ' . $name, 'email' => $this->prefix . '-' . $name . '@example.test',
            'password' => 'demo1234', 'role' => $role, 'eventLimit' => 5,
        ]);
        $this->admin = $make('admin', 'admin');
        $this->moderator = $make('moderator', 'moderator');
        $this->organizer = $make('organizer', 'organizer');
        $this->stranger = $make('organizer', 'stranger');
        $this->participant = $make('participant', 'participant');

        $this->eventId = $this->createEvent('one');
        $this->otherEventId = $this->createEvent('two');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE idempotency_key LIKE :p')->execute(['p' => $this->prefix . '%']);
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM mail_templates WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM activity_logs WHERE target_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    private function createEvent(string $suffix): string
    {
        $event = $this->events->createForOrganizer([
            'name' => 'Mail Log Event ' . $suffix, 'slug' => $this->prefix . '-' . $suffix, 'discipline' => 'running',
            'date' => '2026-09-20', 'location' => 'Stadion', 'city' => 'Warszawa', 'description' => '<p>x</p>', 'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'], 'organizerName' => $this->organizer['name'], 'status' => 'draft', 'registrationDeadline' => '2026-09-01',
            'featured' => false, 'isPrivate' => false, 'paymentConfig' => ['type' => 'admin_gateway'], 'distances' => [],
        ], $this->organizer, $this->organizer['id']);

        return (string) $event['id'];
    }

    private function publish(string $eventId): void
    {
        $this->pdo->prepare("UPDATE events SET publication_status = 'published' WHERE id = :id")->execute(['id' => $eventId]);
    }

    private function as(array $user): array
    {
        return $this->bearer($user['id'], $user['role'], $user['email']);
    }

    private function queue(string $mailType, ?string $eventId, string $to, string $key): array
    {
        return $this->outbox->create([
            'sourceEntityType' => $mailType, 'sourceEntityId' => 'src-' . $key, 'idempotencyKey' => $this->prefix . ':' . $key,
            'priority' => 'normal', 'recipient' => $to, 'subject' => 'Temat ' . $key, 'html' => '<p>SECRET-BODY</p>', 'contextId' => $eventId,
        ]);
    }

    // ---- preview -----------------------------------------------------------------------------------

    public function testGlobalPreviewRendersDraftWithSampleVariablesAndWritesNothing(): void
    {
        $response = $this->request('POST', '/api/v1/admin/mail-templates/payment_confirmed/preview', [
            'subject' => 'Bilet: {{eventName}}',
            'bodyHtml' => '<p>Start {{distanceName}} <a href="{{myStartsLink}}">moje starty</a> {{nope}}</p>',
        ], $this->as($this->admin));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('payment_confirmed', $data['mailType']);
        self::assertNull($data['eventId']);
        self::assertSame('draft', $data['source']);
        self::assertSame('Bilet: Przykładowy Bieg Uliczny 2026', $data['subject']);
        self::assertStringContainsString('Start 10 km', $data['bodyHtml']);
        self::assertStringContainsString('href="https://zmierzymyczas.pl/moje-starty"', $data['bodyHtml']);
        self::assertStringContainsString('{{nope}}', $data['bodyHtml'], 'unknown placeholders stay visible, exactly as on a real send');
        self::assertSame(['nope'], $data['unknownVariables']);
        self::assertSame(['eventName', 'distanceName', 'myStartsLink'], array_keys($data['variables']));
        self::assertSame('Bilet: {{eventName}}', $data['template']['subject']);

        $saved = $this->pdo->query("SELECT COUNT(*) FROM mail_templates WHERE body_html LIKE '%{{nope}}%'");
        self::assertSame(0, (int) $saved->fetchColumn(), 'a preview must never persist the draft');
    }

    public function testPreviewWithoutDraftRendersTheSavedEffectiveTemplate(): void
    {
        $response = $this->request('POST', '/api/v1/admin/mail-templates/email_verification/preview', [], $this->as($this->admin));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertNotSame('draft', $data['source']);
        self::assertStringContainsString('https://zmierzymyczas.pl/auth/verify', $data['bodyHtml']);
        self::assertSame([], $data['unknownVariables']);
    }

    public function testEventPreviewUsesEventOverrideAndRealEventName(): void
    {
        $saved = $this->request('PUT', '/api/v1/events/' . $this->eventId . '/mail-templates/payment_confirmed', [
            'subject' => 'Nadpisany {{eventName}}', 'bodyHtml' => '<p>Override {{eventName}}</p>',
        ], $this->as($this->organizer));
        self::assertSame(200, $saved->getStatusCode());

        $response = $this->request('POST', '/api/v1/events/' . $this->eventId . '/mail-templates/payment_confirmed/preview', [], $this->as($this->organizer));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame($this->eventId, $data['eventId']);
        self::assertSame('event_override', $data['source']);
        self::assertSame('Nadpisany Mail Log Event one', $data['subject']);
        self::assertSame('<p>Override Mail Log Event one</p>', $data['bodyHtml']);
    }

    public function testPreviewValidationAndRbac(): void
    {
        $url = '/api/v1/admin/mail-templates/payment_confirmed/preview';
        self::assertSame(404, $this->request('POST', '/api/v1/admin/mail-templates/nope/preview', [], $this->as($this->admin))->getStatusCode());
        // systemic mail type: no per-event template exists, so no per-event preview
        self::assertSame(422, $this->request('POST', '/api/v1/events/' . $this->eventId . '/mail-templates/' . MailCatalog::PASSWORD_RESET . '/preview', [], $this->as($this->organizer))->getStatusCode());

        self::assertSame(401, $this->request('POST', $url)->getStatusCode());
        self::assertSame(403, $this->request('POST', $url, [], $this->as($this->organizer))->getStatusCode());
        self::assertSame(403, $this->request('POST', $url, [], $this->as($this->moderator))->getStatusCode());

        $eventUrl = '/api/v1/events/' . $this->eventId . '/mail-templates/payment_confirmed/preview';
        self::assertSame(401, $this->request('POST', $eventUrl)->getStatusCode());
        self::assertSame(403, $this->request('POST', $eventUrl, [], $this->as($this->participant))->getStatusCode());
        self::assertSame(403, $this->request('POST', $eventUrl, [], $this->as($this->stranger))->getStatusCode());
        // staff manage an event only once it cleared moderation (canManageEvent)
        self::assertSame(403, $this->request('POST', $eventUrl, [], $this->as($this->admin))->getStatusCode());
        $this->publish($this->eventId);
        self::assertSame(200, $this->request('POST', $eventUrl, [], $this->as($this->admin))->getStatusCode());
    }

    // ---- mail log ----------------------------------------------------------------------------------

    public function testAdminLogIsFilteredPaginatedMaskedAndNeverExposesBodies(): void
    {
        $this->queue('payment_confirmed', $this->eventId, 'jan.kowalski@mlogtest.example', 'a');
        $this->queue('event_changed', $this->otherEventId, 'x@mlogtest.example', 'b');
        $row = $this->queue('payment_confirmed', $this->eventId, 'ewa@mlogtest.example', 'c');
        $this->outbox->markRetryFailed((string) $row['id'], 'RuntimeException', null);

        $response = $this->request('GET', '/api/v1/admin/mail-log?search=mlogtest.example&pageSize=2', [], $this->as($this->admin));
        $payload = $this->json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $payload['total']);
        self::assertSame(2, $payload['pageSize']);
        self::assertSame(2, $payload['totalPages']);
        self::assertCount(2, $payload['data']);

        $response = $this->request('GET', '/api/v1/admin/mail-log?search=mlogtest.example&status=queued&mailType=payment_confirmed', [], $this->as($this->admin));
        $payload = $this->json($response);
        self::assertCount(1, $payload['data']);
        $entry = $payload['data'][0];
        self::assertSame(['id', 'mailType', 'priority', 'recipient', 'subject', 'eventId', 'status', 'attempts', 'nextAttemptAt', 'createdAt', 'sentToMailerAt', 'lastCheckedAt', 'lastError'], array_keys($entry));
        self::assertSame('payment_confirmed', $entry['mailType']);
        self::assertSame('queued', $entry['status']);
        self::assertSame(0, $entry['attempts']);
        self::assertSame('j***@mlogtest.example', $entry['recipient']);
        self::assertStringNotContainsString('SECRET-BODY', (string) $response->getBody());
        self::assertStringNotContainsStringIgnoringCase('idempotency', (string) $response->getBody());

        $failed = $this->json($this->request('GET', '/api/v1/admin/mail-log?search=ewa%40mlogtest.example&status=failed', [], $this->as($this->admin)))['data'];
        self::assertCount(1, $failed);
        self::assertSame('RuntimeException', $failed[0]['lastError']);
    }

    public function testEventLogIsScopedToTheEventAndOmitsInternalErrorsAndSearch(): void
    {
        $this->queue('payment_confirmed', $this->eventId, 'mine@mlogtest.example', 'mine');
        $this->queue('payment_confirmed', $this->otherEventId, 'other@mlogtest.example', 'other');

        $response = $this->request('GET', '/api/v1/events/' . $this->eventId . '/mail-log?search=other&eventId=' . $this->otherEventId . '&contextId=' . $this->otherEventId, [], $this->as($this->organizer));
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertCount(1, $data, 'search and contextId from the query must not widen or redirect the event scope');
        self::assertSame($this->eventId, $data[0]['eventId']);
        self::assertSame('m***@mlogtest.example', $data[0]['recipient']);
        self::assertArrayNotHasKey('lastError', $data[0]);
    }

    public function testLogRbac(): void
    {
        $global = '/api/v1/admin/mail-log';
        self::assertSame(401, $this->request('GET', $global)->getStatusCode());
        self::assertSame(403, $this->request('GET', $global, [], $this->as($this->organizer))->getStatusCode());
        self::assertSame(403, $this->request('GET', $global, [], $this->as($this->moderator))->getStatusCode());
        self::assertSame(200, $this->request('GET', $global, [], $this->as($this->admin))->getStatusCode());

        $event = '/api/v1/events/' . $this->eventId . '/mail-log';
        self::assertSame(401, $this->request('GET', $event)->getStatusCode());
        self::assertSame(403, $this->request('GET', $event, [], $this->as($this->participant))->getStatusCode());
        self::assertSame(403, $this->request('GET', $event, [], $this->as($this->stranger))->getStatusCode());
        self::assertSame(200, $this->request('GET', $event, [], $this->as($this->organizer))->getStatusCode());
        self::assertSame(403, $this->request('GET', $event, [], $this->as($this->admin))->getStatusCode());
        $this->publish($this->eventId);
        self::assertSame(200, $this->request('GET', $event, [], $this->as($this->admin))->getStatusCode());
    }
}
