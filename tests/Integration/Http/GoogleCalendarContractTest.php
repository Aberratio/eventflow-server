<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;
use ZmierzymyCzas\Tests\Support\RsaTestKey;

/**
 * R-17 (umowa 5.9 / 4.4.13): /calendar/settings and /calendar/events are admin-only, the service-account
 * key is never exposed, a missing key keeps the integration inactive (nothing queued, nothing sent), and
 * approving an Event only enqueues - it never calls Google inline. Tests whose name contains "WithKey" run
 * with a temporary key file wired through GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE (routes read it at app build).
 */
final class GoogleCalendarContractTest extends HttpTestCase
{
    private const SETTINGS_ID = '00000000-0000-0000-0000-000000000001';

    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private ?string $keyFile = null;
    private array $admin;
    private array $moderator;
    private array $organizer;

    protected function setUp(): void
    {
        $this->prefix = 'gcal-' . bin2hex(random_bytes(4));
        Env::load(__DIR__ . '/../../../.env');
        if (str_contains($this->name(), 'WithKey')) {
            $this->keyFile = $this->writeKeyFile();
            Env::set('GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE', $this->keyFile);
        } else {
            Env::set('GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE', '');
        }
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->resetSettings();
        $this->admin = $this->createUser('admin');
        $this->moderator = $this->createUser('moderator');
        $this->organizer = $this->createUser('organizer');
    }

    protected function tearDown(): void
    {
        Env::restore('GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE');
        if ($this->keyFile !== null) {
            @unlink($this->keyFile);
        }
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->resetSettings();
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :s');
        $stmt->execute(['s' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM google_calendar_outbox WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM activity_logs WHERE user_id IN (SELECT id FROM users WHERE email LIKE :e)')->execute(['e' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :e')->execute(['e' => $this->prefix . '-%@example.test']);
    }

    public function testSettingsRoutesRequireAdminRole(): void
    {
        foreach ([['GET', '/api/v1/calendar/settings'], ['PATCH', '/api/v1/calendar/settings'], ['POST', '/api/v1/calendar/events']] as [$method, $path]) {
            self::assertSame(401, $this->request($method, $path, ['enabled' => true])->getStatusCode(), "$method $path anonymous");
            self::assertSame(403, $this->request($method, $path, ['enabled' => true], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']))->getStatusCode(), "$method $path moderator");
            self::assertSame(403, $this->request($method, $path, ['enabled' => true], $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']))->getStatusCode(), "$method $path organizer");
        }
    }

    public function testDefaultSettingsReportNoCredentialsAndNeverLeakSecrets(): void
    {
        $response = $this->get();
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertFalse($data['enabled']);
        self::assertFalse($data['connected']);
        self::assertFalse($data['credentialsConfigured']);
        self::assertSame('', $data['calendarId']);
        self::assertSame(['enabled', 'connected', 'credentialsConfigured', 'calendarId', 'timeZone', 'lastSyncAt'], array_keys($data));
    }

    public function testPatchValidatesAndStoresOnlyWritableFields(): void
    {
        $noId = $this->patch(['enabled' => true]);
        self::assertSame(422, $noId->getStatusCode());
        self::assertSame(422, $this->patch(['timeZone' => 'Mars/Olympus'])->getStatusCode());
        self::assertSame(422, $this->patch(['calendarId' => 'has space'])->getStatusCode());

        $ok = $this->patch(['enabled' => true, 'calendarId' => 'zawody@group.calendar.google.com', 'connected' => true, 'privateKey' => 'x', 'lastSyncAt' => '2020-01-01T00:00:00+00:00']);
        self::assertSame(200, $ok->getStatusCode());
        $data = $this->json($ok)['data'];
        self::assertTrue($data['enabled']);
        self::assertSame('zawody@group.calendar.google.com', $data['calendarId']);
        // No key on this server: derived flags are not client-writable.
        self::assertFalse($data['connected']);
        self::assertFalse($data['credentialsConfigured']);
        self::assertNull($data['lastSyncAt']);
    }

    public function testApproveWithoutKeyQueuesNothingEvenWhenEnabled(): void
    {
        $this->patch(['enabled' => true, 'calendarId' => 'zawody@group.calendar.google.com']);
        $event = $this->submittedEvent();

        self::assertSame(200, $this->approve($event['id'])->getStatusCode());
        self::assertSame(0, $this->outboxCount($event['id']));
    }

    public function testManualSendWithoutKeyAnswers409AndSendsNothing(): void
    {
        $this->patch(['enabled' => true, 'calendarId' => 'zawody@group.calendar.google.com']);
        $event = $this->submittedEvent();
        $this->approve($event['id']);

        $response = $this->request('POST', '/api/v1/calendar/events', ['id' => $event['id']], $this->adminAuth());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(0, $this->outboxCount($event['id']));
    }

    public function testSettingsReportCredentialsConfiguredWithKey(): void
    {
        $this->patch(['enabled' => true, 'calendarId' => 'zawody@group.calendar.google.com']);
        $data = $this->json($this->get())['data'];
        self::assertTrue($data['credentialsConfigured']);
        self::assertTrue($data['connected']);
        self::assertStringNotContainsString('PRIVATE KEY', (string) $this->get()->getBody());
    }

    public function testApproveWithKeyAndEnabledQueuesOneEntryWithoutBlockingApproval(): void
    {
        $this->patch(['enabled' => true, 'calendarId' => 'zawody@group.calendar.google.com']);
        $event = $this->submittedEvent();

        $response = $this->approve($event['id']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('published', $this->json($response)['data']['publicationStatus']);
        self::assertSame(1, $this->outboxCount($event['id'], 'queued'));
    }

    public function testApproveWithKeyButIntegrationDisabledQueuesNothing(): void
    {
        $event = $this->submittedEvent();

        self::assertSame(200, $this->approve($event['id'])->getStatusCode());
        self::assertSame(0, $this->outboxCount($event['id']));
    }

    private function get(): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('GET', '/api/v1/calendar/settings', [], $this->adminAuth());
    }

    private function patch(array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/calendar/settings', $body, $this->adminAuth());
    }

    private function approve(string $eventId): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/api/v1/events/' . $eventId . '/approve', [], $this->adminAuth());
    }

    private function adminAuth(): array
    {
        return $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
    }

    private function outboxCount(string $eventId, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM google_calendar_outbox WHERE event_id = :id' . ($status !== null ? ' AND status = :s' : '');
        $params = ['id' => $eventId] + ($status !== null ? ['s' => $status] : []);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function resetSettings(): void
    {
        $this->pdo->prepare("UPDATE google_calendar_settings SET enabled = 0, calendar_id = '', time_zone = 'Europe/Warsaw', last_sync_at = NULL WHERE id = :id")
            ->execute(['id' => self::SETTINGS_ID]);
    }

    private function createUser(string $role): array
    {
        return $this->users->create([
            'name' => 'Gcal ' . $role,
            'email' => $this->prefix . '-' . $role . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
            'eventLimit' => 5,
        ]);
    }

    private function submittedEvent(): array
    {
        $slug = $this->prefix . '-event';
        $event = $this->events->create([
            'name' => 'Gcal Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2027-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
            'status' => 'draft',
            'registrationDeadline' => '2027-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [],
        ]);
        $this->pdo->prepare("UPDATE events SET publication_status = 'submitted' WHERE id = :id")->execute(['id' => $event['id']]);

        return $event;
    }

    private function writeKeyFile(): string
    {
        $pem = RsaTestKey::generate()['private'];
        $path = tempnam(sys_get_temp_dir(), 'gcal');
        file_put_contents($path, json_encode(['client_email' => 'svc@example-project.iam.gserviceaccount.com', 'private_key' => $pem]));

        return $path;
    }
}
