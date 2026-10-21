<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use ZmierzymyCzas\Domain\Events\DiscountCodeBatchRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * `POST|GET /events/{eventId}/discount-code-batches[...]` - the batch generator over
 * event_discount_codes.
 */
final class DiscountCodeBatchContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $organizer;
    private array $stranger;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'dcb-' . bin2hex(random_bytes(4));
        $this->organizer = $this->makeUser('organizer', 'organizer');
        $this->stranger = $this->makeUser('stranger', 'organizer');
        $this->admin = $this->makeUser('admin', 'admin');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM activity_logs WHERE target_type = :t AND target_id = :id')->execute(['t' => 'event', 'id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_code_batches WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOwnerGeneratesABatchOfUniqueUnambiguousCodes(): void
    {
        $eventId = $this->createEvent();

        $response = $this->post($eventId, $this->validBody(['count' => 50, 'prefix' => 'sponsor', 'label' => 'Sponsor X']), $this->organizer);

        self::assertSame(201, $response->getStatusCode());
        $batch = $this->json($response)['data'];
        self::assertSame('Sponsor X', $batch['label']);
        self::assertSame('SPONSOR', $batch['prefix']);
        self::assertSame(50, $batch['count']);
        self::assertSame('percent', $batch['discountType']);
        self::assertSame(1, $batch['maxUses']);
        self::assertCount(50, $batch['codes']);
        $codes = array_column($batch['codes'], 'code');
        self::assertCount(50, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^SPONSOR-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
        }

        // Plain event_discount_codes rows: registration-time validation needs no change.
        $row = $this->pdo->query("SELECT * FROM event_discount_codes WHERE batch_id = '" . $batch['id'] . "' LIMIT 1")->fetch();
        self::assertSame($eventId, $row['event_id']);
        self::assertSame('percent', $row['discount_type']);
        self::assertSame('1', (string) $row['max_uses']);
        self::assertSame('0', (string) $row['used_count']);
        self::assertSame('1', (string) $row['active']);
        $event = $this->events->find($eventId);
        self::assertCount(50, $event['discountCodes']);

        $log = $this->pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'discount_batch_generated' AND target_id = :id");
        $log->execute(['id' => $eventId]);
        self::assertSame(1, (int) $log->fetchColumn());
    }

    public function testCodesDoNotCollideWithExistingCodesAndSupportAmountTypeAndValidity(): void
    {
        $eventId = $this->createEvent();
        $first = $this->json($this->post($eventId, $this->validBody(['count' => 5]), $this->organizer))['data'];
        $second = $this->json($this->post($eventId, $this->validBody([
            'count' => 5,
            'discountType' => 'fixed',
            'discountValue' => '25.50',
            'maxUses' => 3,
            'validFrom' => '2026-05-01',
            'validTo' => '2026-06-01',
        ]), $this->organizer))['data'];

        self::assertSame('fixed', $second['discountType']);
        self::assertSame(25.5, $second['discountValue']);
        self::assertSame(3, $second['maxUses']);
        self::assertSame('2026-05-01', $second['validFrom']);
        self::assertSame('2026-06-01', $second['validTo']);
        $all = array_merge(array_column($first['codes'], 'code'), array_column($second['codes'], 'code'));
        self::assertCount(10, array_unique($all));
        // No prefix and no label: a default label is still provided.
        self::assertNotSame('', $first['label']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'count zero' => [['count' => 0], 'count'];
        yield 'count over cap' => [['count' => 1001], 'count'];
        yield 'count missing' => [['count' => null], 'count'];
        yield 'count not integer' => [['count' => 'abc'], 'count'];
        yield 'bad type' => [['discountType' => 'bogus'], 'discountType'];
        yield 'percent over 100' => [['discountValue' => 101], 'discountValue'];
        yield 'zero value' => [['discountValue' => 0], 'discountValue'];
        yield 'value missing' => [['discountValue' => null], 'discountValue'];
        yield 'bad prefix' => [['prefix' => 'a b!'], 'prefix'];
        yield 'prefix too long' => [['prefix' => str_repeat('A', 21)], 'prefix'];
        yield 'maxUses zero' => [['maxUses' => 0], 'maxUses'];
        yield 'bad date' => [['validFrom' => 'not-a-date'], 'validFrom'];
        yield 'to before from' => [['validFrom' => '2026-06-01', 'validTo' => '2026-05-01'], 'validTo'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidBodies')]
    public function testInvalidInputIsRejectedWith422AndNothingIsWritten(array $override, string $field): void
    {
        $eventId = $this->createEvent();

        $response = $this->post($eventId, $this->validBody($override), $this->organizer);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('validation_error', $error['code']);
        self::assertSame($field, $error['details']['field']);
        self::assertSame([], $this->events->find($eventId)['discountCodes']);
        self::assertSame([], $this->json($this->get('/api/v1/events/' . $eventId . '/discount-code-batches', $this->organizer))['data']);
    }

    public function testCapOf1000CodesIsAccepted(): void
    {
        $eventId = $this->createEvent();

        $response = $this->post($eventId, $this->validBody(['count' => 1000]), $this->organizer);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1000, $this->json($response)['data']['codes']);
    }

    public function testAccessControl(): void
    {
        $eventId = $this->createEvent('active');
        $batchId = (string) $this->json($this->post($eventId, $this->validBody(['count' => 2]), $this->organizer))['data']['id'];
        $base = '/api/v1/events/' . $eventId . '/discount-code-batches';

        self::assertSame(401, $this->request('POST', $base, $this->validBody())->getStatusCode());
        self::assertSame(401, $this->request('GET', $base)->getStatusCode());
        self::assertSame(403, $this->post($eventId, $this->validBody(), $this->stranger)->getStatusCode());
        self::assertSame(403, $this->get($base, $this->stranger)->getStatusCode());
        self::assertSame(403, $this->get($base . '/' . $batchId, $this->stranger)->getStatusCode());
        self::assertSame(403, $this->get($base . '/' . $batchId . '/export', $this->stranger)->getStatusCode());
        self::assertSame(201, $this->post($eventId, $this->validBody(['count' => 1]), $this->admin)->getStatusCode());
        self::assertSame(200, $this->get($base . '/' . $batchId . '/export', $this->admin)->getStatusCode());
    }

    public function testListAndDetailReportUsageAndAreScopedToTheEvent(): void
    {
        $eventId = $this->createEvent();
        $otherEventId = $this->createEvent();
        $batch = $this->json($this->post($eventId, $this->validBody(['count' => 3, 'label' => 'A']), $this->organizer))['data'];
        $this->pdo->prepare('UPDATE event_discount_codes SET used_count = 1 WHERE id = :id')->execute(['id' => $batch['codes'][0]['id']]);

        $list = $this->json($this->get('/api/v1/events/' . $eventId . '/discount-code-batches', $this->organizer))['data'];
        self::assertCount(1, $list);
        self::assertSame($batch['id'], $list[0]['id']);
        self::assertSame(3, $list[0]['codesTotal']);
        self::assertSame(1, $list[0]['usedTotal']);
        self::assertArrayNotHasKey('codes', $list[0]);

        $detail = $this->json($this->get('/api/v1/events/' . $eventId . '/discount-code-batches/' . $batch['id'], $this->organizer))['data'];
        self::assertCount(3, $detail['codes']);
        self::assertSame(1, $detail['usedTotal']);

        // A batch id of another event is "not found", never leaked through a different event.
        self::assertSame(404, $this->get('/api/v1/events/' . $otherEventId . '/discount-code-batches/' . $batch['id'], $this->organizer)->getStatusCode());
        self::assertSame(404, $this->get('/api/v1/events/' . $otherEventId . '/discount-code-batches/' . $batch['id'] . '/export', $this->organizer)->getStatusCode());
        self::assertSame([], $this->json($this->get('/api/v1/events/' . $otherEventId . '/discount-code-batches', $this->organizer))['data']);
        self::assertSame(404, $this->get('/api/v1/events/' . $eventId . '/discount-code-batches/does-not-exist', $this->organizer)->getStatusCode());
    }

    public function testCsvExportHasHeadersEscapingAndIsLogged(): void
    {
        $eventId = $this->createEvent();
        $batch = $this->json($this->post($eventId, $this->validBody([
            'count' => 3,
            'prefix' => 'VIP',
            'label' => '=HYPERLINK("http://evil.test";"x"), "Łódź"',
            'discountType' => 'fixed',
            'discountValue' => 20,
        ]), $this->organizer))['data'];

        $response = $this->get('/api/v1/events/' . $eventId . '/discount-code-batches/' . $batch['id'] . '/export', $this->organizer);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment; filename="kody-rabatowe-VIP.csv"', $response->getHeaderLine('Content-Disposition'));
        $csv = (string) iconv('Windows-1250', 'UTF-8', (string) $response->getBody());
        $rows = array_map(static fn (string $l): array => str_getcsv($l, ';', '"', ''), array_values(array_filter(explode("\n", str_replace("\r", '', $csv)))));
        self::assertSame(['Kod', 'Rodzaj rabatu', 'Wartość rabatu', 'Limit użyć', 'Użycia', 'Ważny od', 'Ważny do', 'Partia'], $rows[0]);
        self::assertCount(4, $rows);
        $exported = array_column(array_slice($rows, 1), 0);
        self::assertEqualsCanonicalizing(array_column($batch['codes'], 'code'), $exported);
        self::assertSame('kwota', $rows[1][1]);
        // Formula injection: the label starts with "=" and must be neutralised, quotes survive parsing.
        self::assertSame('\'=HYPERLINK("http://evil.test";"x"), "Łódź"', $rows[1][7]);

        $log = $this->pdo->prepare("SELECT user_id FROM activity_logs WHERE action = 'discount_batch_exported' AND target_id = :id");
        $log->execute(['id' => $eventId]);
        self::assertSame($this->organizer['id'], $log->fetchColumn());
    }

    public function testCsvCellNeutralisesFormulaCharacters(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $dangerous) {
            self::assertSame("'" . $dangerous, DiscountCodeBatchRepository::csvCell($dangerous));
        }
        self::assertSame('ABC-1', DiscountCodeBatchRepository::csvCell('ABC-1'));
        self::assertSame('', DiscountCodeBatchRepository::csvCell(''));
    }

    public function testEventSaveKeepsBatchMembershipOfGeneratedCodes(): void
    {
        $eventId = $this->createEvent();
        $batch = $this->json($this->post($eventId, $this->validBody(['count' => 2]), $this->organizer))['data'];

        $event = $this->events->find($eventId);
        self::assertSame($batch['id'], $event['discountCodes'][0]['batchId']);
        // The client echoes the codes back (batchId included) on a normal event save.
        $this->events->update($eventId, ['discountCodes' => $event['discountCodes']]);

        $after = $this->json($this->get('/api/v1/events/' . $eventId . '/discount-code-batches/' . $batch['id'], $this->organizer))['data'];
        self::assertCount(2, $after['codes']);
    }

    /** @param array<string, mixed> $override @return array<string, mixed> */
    private function validBody(array $override = []): array
    {
        return array_replace(['count' => 2, 'discountType' => 'percent', 'discountValue' => 10], $override);
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $actor */
    private function post(string $eventId, array $body, array $actor): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/api/v1/events/' . $eventId . '/discount-code-batches', $body, $this->auth($actor));
    }

    /** @param array<string, mixed> $actor */
    private function get(string $path, array $actor): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('GET', $path, [], $this->auth($actor));
    }

    /** @param array<string, mixed> $actor @return array<string, string> */
    private function auth(array $actor): array
    {
        return $this->bearer((string) $actor['id'], (string) $actor['role'], (string) $actor['email']);
    }

    /** @return array<string, mixed> */
    private function makeUser(string $name, string $role): array
    {
        return $this->users->create([
            'name' => 'Batch ' . $name,
            'email' => $this->prefix . '-' . $name . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
            'eventLimit' => 5,
        ]);
    }

    private function createEvent(string $status = 'draft'): string
    {
        $slug = $this->prefix . '-event-' . bin2hex(random_bytes(3));
        $event = $this->events->createForOrganizer([
            'name' => 'Batch Contract Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
            'status' => $status,
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

        if ($status === 'active') {
            $this->pdo->prepare("UPDATE events SET publication_status = 'published' WHERE id = :id")->execute(['id' => $event['id']]);
        }

        return (string) $event['id'];
    }
}
