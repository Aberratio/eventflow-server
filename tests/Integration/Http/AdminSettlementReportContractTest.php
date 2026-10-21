<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Payments\SettlementReport;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * R-03: GET /admin/settlements (+ /export) - raport rozliczeniowy trasy A. Fixture: dwa wydarzenia,
 * wplaty bramki (paid / created / poza okresem) i zwroty (gateway / manual / poza okresem).
 */
final class AdminSettlementReportContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private string $prefix;
    private array $admin;
    private array $organizer;
    private string $eventA;
    private string $eventB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->prefix = 'rstl-' . bin2hex(random_bytes(4));
        $this->admin = $this->makeUser('admin', 'admin');
        $this->organizer = $this->makeUser('org', 'organizer');

        $this->eventA = $this->makeEvent('a');
        $this->eventB = $this->makeEvent('b');

        // Wydarzenie A: 100.00 + 50.50 wplaty w okresie, 20.25 zwrotu w okresie.
        $regA1 = $this->makeRegistration($this->eventA, 'A1');
        $regA2 = $this->makeRegistration($this->eventA, 'A2');
        $this->insertTransaction($regA1, 'paid', '100.00', '2026-03-10T10:00:00+01:00');
        $this->insertTransaction($regA2, 'paid', '50.50', '2026-03-31T23:30:00+02:00');
        $this->insertRefund($regA1, 'refunded', 'gateway', '20.25', '2026-03-20T09:00:00+01:00');
        // Nie wchodza: wplata niedokonczona, wplata poza okresem, zwrot recznej trasy, zwrot niezakonczony, zwrot poza okresem.
        $regA3 = $this->makeRegistration($this->eventA, 'A3');
        $this->insertTransaction($regA3, 'created', '999.00', null);
        $regA4 = $this->makeRegistration($this->eventA, 'A4');
        $this->insertTransaction($regA4, 'paid', '77.00', '2026-04-01T00:00:01+02:00');
        $this->insertRefund($regA4, 'refunded', 'gateway', '11.00', '2026-02-28T10:00:00+01:00');
        $regA5 = $this->makeRegistration($this->eventA, 'A5');
        $this->insertRefund($regA5, 'refunded', 'manual', '33.00', '2026-03-12T10:00:00+01:00');
        $regA6 = $this->makeRegistration($this->eventA, 'A6');
        $this->insertRefund($regA6, 'approved', 'gateway', '44.00', null);

        // Wydarzenie B: 30.00 wplata w okresie.
        $regB1 = $this->makeRegistration($this->eventB, 'B1');
        $this->insertTransaction($regB1, 'paid', '30.00', '2026-03-05T12:00:00+01:00');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $ids = $this->pdo->prepare('SELECT id FROM registrations WHERE event_name LIKE :p');
        $ids->execute(['p' => $this->prefix . '%']);
        foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $registrationId) {
            $this->pdo->prepare('DELETE FROM registration_refunds WHERE registration_id = :id')->execute(['id' => $registrationId]);
            $this->pdo->prepare('DELETE FROM registration_gateway_transactions WHERE registration_id = :id')->execute(['id' => $registrationId]);
        }
        $this->pdo->prepare('DELETE FROM activity_logs WHERE action = :a AND user_id = :u')->execute(['a' => 'settlement_exported', 'u' => $this->admin['id']]);
        foreach ([$this->eventA ?? null, $this->eventB ?? null] as $eventId) {
            if ($eventId === null) {
                continue;
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :name')->execute(['name' => 'Settlement%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testSummaryAndPerEventTotalsAreInIntegerMinorUnits(): void
    {
        $data = $this->fetch('/api/v1/admin/settlements?dateFrom=2026-03-01&dateTo=2026-03-31');

        self::assertSame(['dateFrom' => '2026-03-01', 'dateTo' => '2026-03-31'], $data['period']);
        self::assertSame(0, $data['commissionRateBps']);
        $byEvent = [];
        foreach ($data['events'] as $event) {
            $byEvent[$event['eventId']] = $event;
        }
        $a = $byEvent[$this->eventA];
        self::assertSame(2, $a['paymentsCount']);
        self::assertSame(15050, $a['grossMinor']);
        self::assertSame(1, $a['refundsCount']);
        self::assertSame(2025, $a['refundsMinor']);
        self::assertSame(0, $a['commissionMinor']);
        self::assertSame(13025, $a['netMinor']);
        $b = $byEvent[$this->eventB];
        self::assertSame(3000, $b['grossMinor']);
        self::assertSame(3000, $b['netMinor']);
        self::assertSame($this->organizer['name'], $b['organizerName']);

        // Wlasne wiersze sa deterministyczne; inne dane w bazie moga tylko dolozyc do sumy.
        self::assertGreaterThanOrEqual(18050, $data['summary']['grossMinor']);
        self::assertSame($data['summary']['grossMinor'] - $data['summary']['refundsMinor'] - $data['summary']['commissionMinor'], $data['summary']['netMinor']);
    }

    public function testEventFilterReturnsLinesPerPaymentAndRefund(): void
    {
        $data = $this->fetch('/api/v1/admin/settlements?dateFrom=2026-03-01&dateTo=2026-03-31&eventId=' . $this->eventA);

        self::assertCount(1, $data['events']);
        self::assertSame(15050, $data['summary']['grossMinor']);
        self::assertSame(2025, $data['summary']['refundsMinor']);
        self::assertSame(13025, $data['summary']['netMinor']);
        self::assertCount(3, $data['lines']);
        $types = array_column($data['lines'], 'type');
        sort($types);
        self::assertSame(['payment', 'payment', 'refund'], $types);
        foreach ($data['lines'] as $line) {
            self::assertIsInt($line['amountMinor']);
            self::assertSame($this->eventA, $line['eventId']);
        }
        $amounts = array_column($data['lines'], 'amountMinor');
        sort($amounts);
        self::assertSame([2025, 5050, 10000], $amounts);
    }

    public function testPeriodBoundariesAreInclusiveOfWholeDays(): void
    {
        $data = $this->fetch('/api/v1/admin/settlements?dateFrom=2026-04-01&dateTo=2026-04-01&eventId=' . $this->eventA);

        self::assertSame(7700, $data['summary']['grossMinor']);
        self::assertSame(0, $data['summary']['refundsMinor']);
    }

    public function testCommissionIsChargedOnPaymentsAndReversedOnRefunds(): void
    {
        $report = (new SettlementReport($this->pdo, 250))->report('2026-03-01', '2026-03-31', $this->eventA);

        // 2.5% z 100.00 = 250, z 50.50 = 126 (126.25), z zwrotu 20.25 = 51 (50.6).
        self::assertSame(250, $report['commissionRateBps']);
        self::assertSame(376 - 51, $report['summary']['commissionMinor']);
        self::assertSame(15050 - 2025 - 325, $report['summary']['netMinor']);
    }

    public function testInvalidPeriodIsRejected(): void
    {
        $auth = $this->adminAuth();
        self::assertSame(422, $this->request('GET', '/api/v1/admin/settlements', [], $auth)->getStatusCode());
        self::assertSame(422, $this->request('GET', '/api/v1/admin/settlements?dateFrom=2026-02-30&dateTo=2026-03-01', [], $auth)->getStatusCode());
        self::assertSame(422, $this->request('GET', '/api/v1/admin/settlements?dateFrom=2026-04-01&dateTo=2026-03-01', [], $auth)->getStatusCode());
        self::assertSame(422, $this->request('GET', '/api/v1/admin/settlements/export?format=pdf&dateFrom=2026-03-01&dateTo=2026-03-31', [], $auth)->getStatusCode());
    }

    public function testCsvExportCarriesLinesTotalsAndIsLogged(): void
    {
        $response = $this->request('GET', '/api/v1/admin/settlements/export?format=csv&dateFrom=2026-03-01&dateTo=2026-03-31&eventId=' . $this->eventA, [], $this->adminAuth());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('rozliczenie-2026-03-01_2026-03-31.csv', $response->getHeaderLine('Content-Disposition'));
        $csv = (string) iconv('Windows-1250', 'UTF-8', (string) $response->getBody());
        self::assertStringContainsString('Kwota (PLN)', $csv);
        self::assertStringContainsString('100,00', $csv);
        self::assertStringContainsString('-20,25', $csv);
        self::assertStringContainsString('"DO WYPŁATY";;;;;;;;130,25', $csv);

        $stmt = $this->pdo->prepare("SELECT id FROM activity_logs WHERE action = 'settlement_exported' AND user_id = :u");
        $stmt->execute(['u' => $this->admin['id']]);
        self::assertNotFalse($stmt->fetch());
    }

    public function testXlsxExportIsServed(): void
    {
        $response = $this->request('GET', '/api/v1/admin/settlements/export?format=xlsx&dateFrom=2026-03-01&dateTo=2026-03-31', [], $this->adminAuth());

        if ($response->getStatusCode() === 500 && str_contains((string) $response->getBody(), 'xlsx_not_supported')) {
            self::markTestSkipped('ZipArchive is required to write XLSX files.');
        }
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('PK', (string) $response->getBody());
    }

    public function testOnlyAdminsMayRead(): void
    {
        foreach (['/api/v1/admin/settlements', '/api/v1/admin/settlements/export'] as $path) {
            $url = $path . '?dateFrom=2026-03-01&dateTo=2026-03-31';
            self::assertSame(401, $this->request('GET', $url)->getStatusCode());
            self::assertSame(403, $this->request('GET', $url, [], $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']))->getStatusCode());
        }
    }

    private function fetch(string $url): array
    {
        $response = $this->request('GET', $url, [], $this->adminAuth());
        self::assertSame(200, $response->getStatusCode());

        return $this->json($response)['data'];
    }

    private function adminAuth(): array
    {
        return $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
    }

    private function makeUser(string $key, string $role): array
    {
        return $this->users->create([
            'name' => 'Settlement ' . $key,
            'email' => $this->prefix . '-' . $key . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
            'eventLimit' => 5,
        ]);
    }

    private function makeEvent(string $key): string
    {
        $slug = $this->prefix . '-' . $key;
        $event = $this->events->createForOrganizer([
            'name' => $this->prefix . ' Event ' . strtoupper($key),
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-10k',
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 80,
                'routeFiles' => [],
            ]],
        ], $this->organizer, $this->organizer['id']);

        return (string) $event['id'];
    }

    private function makeRegistration(string $eventId, string $label): string
    {
        $event = $this->events->find($eventId);
        $competitor = $this->competitors->create(['firstName' => 'Settlement', 'lastName' => $label], skipDuplicateCheck: true);
        $registration = $this->registrations->create([
            'eventId' => $eventId,
            'eventName' => (string) $event['name'],
            'competitorId' => $competitor['id'],
            'userId' => $this->prefix . '-user-' . $label,
            'userName' => 'Settlement ' . $label,
            'userEmail' => $this->prefix . '-reg-' . strtolower($label) . '@example.test',
            'distanceId' => (string) $event['distances'][0]['id'],
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);

        return (string) $registration['id'];
    }

    private function insertTransaction(string $registrationId, string $status, string $amount, ?string $processedAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO registration_gateway_transactions (transaction_id, registration_id, gateway, amount, status, created_at, processed_at)
             VALUES (:t, :r, :g, :a, :s, :c, :p)'
        )->execute([
            't' => $this->prefix . '-' . bin2hex(random_bytes(4)),
            'r' => $registrationId,
            'g' => 'tpay',
            'a' => $amount,
            's' => $status,
            'c' => '2026-01-01T00:00:00+01:00',
            'p' => $processedAt,
        ]);
    }

    private function insertRefund(string $registrationId, string $status, string $route, string $amount, ?string $completedAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO registration_refunds (registration_id, status, amount, route, completed_at) VALUES (:r, :s, :a, :rt, :c)'
        )->execute(['r' => $registrationId, 's' => $status, 'a' => $amount, 'rt' => $route, 'c' => $completedAt]);
    }
}
