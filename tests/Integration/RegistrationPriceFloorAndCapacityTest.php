<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

/**
 * R-12 (specs 07, 21): the Zapis total never drops below 0 when a negative form modifier ("udzial w
 * losowaniu -5") is larger than the price, and the seat counter holds under truly parallel creates.
 */
final class RegistrationPriceFloorAndCapacityTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'preg-floor-' . bin2hex(random_bytes(4));
        $this->pdo = Connection::fromEnv();
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :p');
        $stmt->execute(['p' => $this->slugPrefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $regs = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $regs->execute(['id' => $eventId]);
            foreach ($regs->fetchAll(PDO::FETCH_COLUMN) ?: [] as $regId) {
                foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds'] as $table) {
                    $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
                }
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $eventId]);
        }
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :p')->execute(['p' => $this->slugPrefix . '%']);
    }

    public function testNegativeModifierLargerThanThePriceFloorsTheTotalAtZero(): void
    {
        $event = $this->event('floor', 5, 80);
        $payload = $this->payload($event, 'floor-user') + [
            'formModifierItems' => [['name' => 'Losowanie', 'basis' => 'modifier', 'amount' => -200.0]],
        ];

        $quote = $this->registrations->quote($payload);
        self::assertSame(0.0, $quote['totalAmount'], 'quote floors at 0');
        self::assertEqualsWithDelta(-120.0, array_sum(array_column($quote['settlementItems'], 'amount')), 0.001, 'the lines themselves stay honest');

        $registration = $this->registrations->create($payload);
        self::assertSame(0.0, (float) $registration['totalAmount'], 'DO ZAPLATY of the stored Zapis is floored at 0');
        self::assertEqualsWithDelta(80.0, (float) $registration['pricePaid'], 0.001, 'the start fee line itself is not touched by modifiers');
        self::assertEqualsWithDelta(-120.0, array_sum(array_column($registration['settlementItems'], 'amount')), 0.001);
    }

    public function testNegativeModifierSmallerThanThePriceJustReducesTheTotal(): void
    {
        $event = $this->event('reduce', 5, 80);
        $payload = $this->payload($event, 'reduce-user') + [
            'formModifierItems' => [['name' => 'Losowanie', 'basis' => 'modifier', 'amount' => -5.0]],
        ];

        self::assertSame(75.0, $this->registrations->quote($payload)['totalAmount']);
        self::assertEqualsWithDelta(75.0, (float) $this->registrations->create($payload)['totalAmount'], 0.001);
    }

    public function testSumOfPositiveAndNegativeModifiersIsFlooredAtZeroOnlyOnce(): void
    {
        $event = $this->event('mixed', 5, 10);
        $payload = $this->payload($event, 'mixed-user') + [
            'formModifierItems' => [
                ['name' => 'Posilek', 'basis' => 'modifier', 'amount' => 5.0],
                ['name' => 'Losowanie', 'basis' => 'modifier', 'amount' => -50.0],
            ],
        ];

        self::assertSame(0.0, $this->registrations->quote($payload)['totalAmount']);
    }

    /** Parallel OS processes race for the last seats: never more confirmed Zapisy than the limit, counter == rows. */
    public function testParallelCreatesNeverOversellTheLimit(): void
    {
        $limit = 2;
        $workers = 6;
        $event = $this->event('race', $limit, 20);
        $distanceId = (string) $event['distances'][0]['id'];

        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $payload = $this->payload($event, "race-user-{$i}");
            $cmd = [PHP_BINARY, __DIR__ . '/../Support/create_registration_worker.php', json_encode($payload, JSON_THROW_ON_ERROR)];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), getenv() ?: null);
            self::assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }

        $ok = 0;
        $full = 0;
        $other = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            if ($out === 'ok') {
                $ok++;
            } elseif ($out === 'registration_capacity_exceeded') {
                $full++;
            } else {
                $other[] = $out . ' ' . $err;
            }
        }

        self::assertSame([], $other, 'every worker either got a seat or a clean capacity rejection');
        self::assertSame($limit, $ok, 'exactly the limit gets a seat');
        self::assertSame($workers - $limit, $full);

        $count = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $count->execute(['id' => $distanceId]);
        self::assertSame($limit, (int) $count->fetchColumn());
        $rows = $this->pdo->prepare("SELECT COUNT(*) FROM registrations WHERE distance_id = :id AND status NOT IN ('cancelled','removed','expired')");
        $rows->execute(['id' => $distanceId]);
        self::assertSame($limit, (int) $rows->fetchColumn(), 'counter matches the real rows');
    }

    /** @return array<string, mixed> */
    private function event(string $key, int $limit, int|float $price): array
    {
        $slug = $this->slugPrefix . '-' . $key;

        return $this->events->create([
            'name' => 'PHPUnit Floor ' . $key,
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-06-15',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-06-01',
            'featured' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-d',
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => $limit,
                'registeredCount' => 0,
                'price' => $price,
                'routeFiles' => [],
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function payload(array $event, string $userId): array
    {
        return [
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $userId,
            'userName' => 'PHPUnit User',
            'userEmail' => $userId . '@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'discountCode' => '',
            'merchandiseOrders' => [],
        ];
    }
}
