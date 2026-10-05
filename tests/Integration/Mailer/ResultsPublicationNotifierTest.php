<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Mailer;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\ResultsPublication\ResultsPublicationMailRequests;
use ZmierzymyCzas\Domain\Mailer\ResultsPublication\ResultsPublicationNotifier;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\FakeCentralMailerHttpClient;

/** PP.19: fan-out of "Publikacja wyników" to deferred outbox rows (one per distinct confirmed e-mail per event). */
final class ResultsPublicationNotifierTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private ResultsPublicationMailRequests $requests;
    private CentralMailerOutboxRepository $outboxRepo;
    private FakeCentralMailerHttpClient $http;
    private ResultsPublicationNotifier $notifier;
    private string $slugPrefix;
    /** @var array<string, mixed> */
    private array $event;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../../.env');
        $this->slugPrefix = 'phprespub-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        // Outside any try/catch: a broken migration must fail, not skip.
        Migrator::applyAll($this->pdo);
        $this->deleteFixtures();
        // Other suites (e.g. publishing results over HTTP) leave pending requests behind; the counters here must
        // only see this test's own.
        $this->pdo->exec('DELETE FROM results_publication_mail_requests WHERE processed_at IS NULL');

        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->requests = new ResultsPublicationMailRequests($this->pdo);
        $this->outboxRepo = new CentralMailerOutboxRepository($this->pdo);
        $this->http = new FakeCentralMailerHttpClient();
        $config = new CentralMailerConfig(apiKey: 'k', baseUrl: 'https://mailer.example.test');
        $outbox = new CentralMailerOutboxService(new CentralMailerClient($this->http, $config), $this->outboxRepo);
        $mailer = new CriticalMailer($config, $outbox, new MailTemplateRepository($this->pdo));
        $this->notifier = new ResultsPublicationNotifier($this->pdo, $this->requests, $mailer, $this->outboxRepo, 'https://app.example.test');

        $this->event = $this->events->create($this->eventPayload());
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->deleteFixtures();
        }
    }

    public function testFansOutToDistinctConfirmedRecipientsOfTheDistance(): void
    {
        $this->register('a1', 0, 'confirmed', 'Runner.One@Example.test');
        $this->register('a2', 0, 'confirmed', 'runner.one@example.test');
        $this->register('b1', 0, 'confirmed', 'runner.two@example.test');
        $this->register('c1', 0, 'pending', 'pending@example.test');
        $this->register('d1', 0, 'cancelled', 'cancelled@example.test');
        $this->register('e1', 1, 'confirmed', 'other-distance@example.test');
        $this->requestPublication(0);

        $counters = $this->notifier->run(new \DateTimeImmutable());

        self::assertSame(['requests' => 1, 'queued' => 2, 'skippedDuplicate' => 0], $counters);
        self::assertCount(0, $this->http->calls, 'fan-out makes no HTTP call');
        $rows = $this->outboxRows();
        self::assertCount(2, $rows);
        $recipients = array_map('strtolower', array_column($rows, 'recipient'));
        sort($recipients);
        self::assertSame(['runner.one@example.test', 'runner.two@example.test'], $recipients);
        foreach ($rows as $row) {
            self::assertSame('queued', $row['status']);
            self::assertSame(0, (int) $row['attempts']);
            self::assertNull($row['central_mailer_email_id']);
            self::assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $row['next_attempt_at']);
            self::assertMatchesRegularExpression('/^' . preg_quote((string) $this->event['id'], '/') . ':[0-9a-f]{16}$/', $row['source_entity_id']);
            self::assertSame(53, strlen($row['source_entity_id']));
            self::assertSame($this->event['id'], $row['context_id']);
            self::assertStringContainsString('https://app.example.test/events/' . $this->event['slug'] . '/results', $row['html']);
        }

        $request = $this->requestRow(0);
        self::assertNotNull($request['processed_at']);
        self::assertSame(2, (int) $request['recipients_queued']);
    }

    public function testRepublishAndTemplateBumpDoNotResend(): void
    {
        $this->register('a1', 0, 'confirmed', 'runner.one@example.test');
        $this->register('b1', 1, 'confirmed', 'RUNNER.ONE@example.test');
        $this->requestPublication(0);
        self::assertSame(1, $this->notifier->run(new \DateTimeImmutable())['queued']);

        // Re-publish of the same distance.
        $this->requestPublication(0);
        $again = $this->notifier->run(new \DateTimeImmutable());
        self::assertSame(['requests' => 1, 'queued' => 0, 'skippedDuplicate' => 1], $again);

        // A second distance of the same event for the same address.
        $this->requestPublication(1);
        self::assertSame(0, $this->notifier->run(new \DateTimeImmutable())['queued']);

        // A new template version changes the idempotency key (eventId:hash:results_published:vN), but not
        // the per-event recipient dedup.
        $templates = new MailTemplateRepository($this->pdo);
        $templates->saveEventOverride(MailCatalog::RESULTS_PUBLISHED, (string) $this->event['id'], 'Wyniki {{eventName}}', '<p>{{resultsLink}}</p>', 'phpunit');
        $templates->saveEventOverride(MailCatalog::RESULTS_PUBLISHED, (string) $this->event['id'], 'Wyniki {{eventName}}', '<p>v2 {{resultsLink}}</p>', 'phpunit');
        $this->requestPublication(0);
        self::assertSame(0, $this->notifier->run(new \DateTimeImmutable())['queued']);
        self::assertCount(1, $this->outboxRows());
    }

    public function testNewConfirmedParticipantGetsMailOnRepublish(): void
    {
        $this->register('a1', 0, 'confirmed', 'first@example.test');
        $this->requestPublication(0);
        self::assertSame(1, $this->notifier->run(new \DateTimeImmutable())['queued']);

        $this->register('a2', 0, 'confirmed', 'late@example.test');
        $this->requestPublication(0);
        $counters = $this->notifier->run(new \DateTimeImmutable());

        self::assertSame(['requests' => 1, 'queued' => 1, 'skippedDuplicate' => 1], $counters);
        self::assertCount(2, $this->outboxRows());
    }

    public function testRepublishDuringProcessingKeepsRequestPending(): void
    {
        $eventId = (string) $this->event['id'];
        $distanceId = (string) $this->event['distances'][0]['id'];
        $first = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->requests->request($eventId, $distanceId, $first);
        $seen = $this->requests->listPending(10)[0]['requestedAt'];

        // The organizer republishes while the job is processing.
        $this->requests->request($eventId, $distanceId, new \DateTimeImmutable('2026-01-01 10:00:05'));
        $this->requests->markProcessed($eventId, $distanceId, $seen, 3, new \DateTimeImmutable());

        $row = $this->requestRow(0);
        self::assertNull($row['processed_at'], 'a stale requested_at leaves the request pending');
        self::assertSame(0, (int) $row['recipients_queued']);
        $pending = array_filter($this->requests->listPending(100), static fn (array $r): bool => $r['eventId'] === $eventId);
        self::assertCount(1, $pending);
    }

    public function testUnconfiguredMailerDrainsTheRequestWithoutRows(): void
    {
        $this->register('a1', 0, 'confirmed', 'runner.one@example.test');
        $this->requestPublication(0);
        $config = new CentralMailerConfig(apiKey: '', baseUrl: '');
        $outbox = new CentralMailerOutboxService(new CentralMailerClient($this->http, $config), $this->outboxRepo);
        $notifier = new ResultsPublicationNotifier(
            $this->pdo,
            $this->requests,
            new CriticalMailer($config, $outbox, new MailTemplateRepository($this->pdo)),
            $this->outboxRepo,
            'https://app.example.test'
        );

        $counters = $notifier->run(new \DateTimeImmutable());

        self::assertSame(0, $counters['queued']);
        self::assertCount(0, $this->outboxRows());
        self::assertNotNull($this->requestRow(0)['processed_at']);
    }

    /** @return list<array<string, mixed>> */
    private function outboxRows(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM central_mailer_outbox WHERE source_entity_type = 'results_published' AND source_entity_id LIKE :like ORDER BY recipient"
        );
        $stmt->execute(['like' => $this->event['id'] . ':%']);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    private function requestRow(int $distanceIndex): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM results_publication_mail_requests WHERE event_id = :e AND distance_id = :d');
        $stmt->execute(['e' => $this->event['id'], 'd' => $this->event['distances'][$distanceIndex]['id']]);
        $row = $stmt->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function requestPublication(int $distanceIndex): void
    {
        $this->requests->request((string) $this->event['id'], (string) $this->event['distances'][$distanceIndex]['id'], new \DateTimeImmutable());
    }

    private function register(string $user, int $distanceIndex, string $status, string $email): void
    {
        $distance = $this->event['distances'][$distanceIndex];
        $registration = $this->registrations->create([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => 'phpunit-' . $user . '-' . $this->slugPrefix,
            'userName' => 'PHPUnit User',
            'userEmail' => $email,
            'distanceId' => $distance['id'],
            'distanceName' => $distance['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ]);
        $this->pdo->prepare('UPDATE registrations SET status = :status, user_email = :email WHERE id = :id')
            ->execute(['status' => $status, 'email' => $email, 'id' => $registration['id']]);
    }

    /** @return array<string, mixed> */
    private function eventPayload(): array
    {
        $distance = static fn (string $id, string $name): array => [
            'id' => $id, 'name' => $name, 'length' => 10, 'unit' => 'km', 'categories' => ['Open'],
            'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0,
            'price' => 80, 'routeFiles' => [],
        ];

        return [
            'name' => 'PHPUnit Results Mail Event',
            'slug' => $this->slugPrefix,
            'discipline' => 'running',
            'date' => '2026-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                $distance($this->slugPrefix . '-10k', '10 km'),
                $distance($this->slugPrefix . '-5k', '5 km'),
            ],
        ];
    }

    private function deleteFixtures(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->slugPrefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE context_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM mail_templates WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM results_publication_mail_requests WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }
}
