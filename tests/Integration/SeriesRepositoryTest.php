<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Series\SeriesRepository;
use ZmierzymyCzas\Support\Env;

/**
 * TD.23: SeriesRepository::replaceEvents() was converted from a per-row
 * INSERT+UPDATE loop to a batched multi-row INSERT and a single batched
 * UPDATE. These tests exercise it with a realistic multi-event input (more
 * than one row) so a wrong VALUES-group mapping, a wrong sort_order, or a
 * dropped events.series_id backfill would fail — not just happen to pass
 * because the input was a single row.
 */
final class SeriesRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SeriesRepository $series;
    private EventRepository $events;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpunit-series-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            Migrator::applyAll($this->pdo);
            $this->series = new SeriesRepository($this->pdo);
            $this->events = new EventRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->slugPrefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM series WHERE slug LIKE :slug')->execute(['slug' => $this->slugPrefix . '%']);
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->slugPrefix . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }

    public function testCreateWithFiveEventsBatchesInsertAndPreservesOrderAndBackfillsSeriesId(): void
    {
        $eventIds = [];
        for ($i = 0; $i < 5; $i++) {
            $eventIds[] = $this->createEvent($i);
        }

        $created = $this->series->create([
            'name' => $this->slugPrefix . ' Series',
            'slug' => $this->slugPrefix . '-series',
            'type' => 'local',
            'eventIds' => $eventIds,
        ], ['sub' => 'phpunit-organizer', 'email' => 'organizer@example.test']);

        // Order must be preserved exactly as given (sort_order = input index).
        self::assertSame($eventIds, $created['eventIds']);

        $stmt = $this->pdo->prepare('SELECT event_id AS eventId, sort_order AS sortOrder FROM series_events WHERE series_id = :id ORDER BY sort_order ASC');
        $stmt->execute(['id' => $created['id']]);
        $rows = $stmt->fetchAll();
        self::assertCount(5, $rows);
        foreach ($rows as $index => $row) {
            self::assertSame($eventIds[$index], $row['eventId'], "sort_order {$index} must map to the event at that input position.");
            self::assertSame($index, (int) $row['sortOrder']);
        }

        // Every listed event must have its series_id backfilled — the batched
        // UPDATE ... WHERE id IN (...) must touch all five rows, not just one.
        foreach ($eventIds as $eventId) {
            $event = $this->events->find($eventId);
            self::assertSame($created['id'], $event['seriesId'], "Event {$eventId} must be backfilled with the series id.");
        }
    }

    public function testUpdateReplacesEventListAndClearsRemovedRowsFromSeriesEvents(): void
    {
        $first = $this->createEvent(0);
        $second = $this->createEvent(1);
        $third = $this->createEvent(2);

        $created = $this->series->create([
            'name' => $this->slugPrefix . ' Series 2',
            'slug' => $this->slugPrefix . '-series-2',
            'type' => 'local',
            'eventIds' => [$first, $second],
        ], ['sub' => 'phpunit-organizer', 'email' => 'organizer@example.test']);

        $updated = $this->series->update($created['id'], ['eventIds' => [$third, $first, $second]]);

        self::assertSame([$third, $first, $second], $updated['eventIds']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM series_events WHERE series_id = :id');
        $stmt->execute(['id' => $created['id']]);
        self::assertSame(3, (int) $stmt->fetchColumn(), 'The old two-row set must be fully replaced, not appended to.');
    }

    public function testEmptyEventIdsLeavesSeriesEventsEmptyWithoutError(): void
    {
        $created = $this->series->create([
            'name' => $this->slugPrefix . ' Series Empty',
            'slug' => $this->slugPrefix . '-series-empty',
            'type' => 'local',
            'eventIds' => [],
        ], ['sub' => 'phpunit-organizer', 'email' => 'organizer@example.test']);

        self::assertSame([], $created['eventIds']);
    }

    private function createEvent(int $index): string
    {
        $slug = $this->slugPrefix . '-event-' . $index;
        $event = $this->events->create([
            'name' => 'PHPUnit Series Event ' . $index,
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+' . (6 + $index) . ' months')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'draft',
            'registrationDeadline' => date('Y-m-d', strtotime('+' . (5 + $index) . ' months')),
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-10k',
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'startType' => 'individual',
            ]],
        ]);
        return (string) $event['id'];
    }
}
