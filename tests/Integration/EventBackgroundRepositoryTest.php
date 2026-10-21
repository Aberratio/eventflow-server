<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventBackgroundRepository;
use ZmierzymyCzas\Support\Env;

/**
 * EventBackgroundRepository had zero direct test coverage before this
 * file. Covers create()/update()'s trimming and merge semantics (shared
 * shape with PartnerRepository) plus usages(), the one method unique to this
 * repository: it drives the "can this background be deleted / is it in
 * use" check in the admin UI, backed by a real FK
 * (events.background_id -> event_backgrounds.id), so it needs to actually
 * find events that reference it and not find ones that don't.
 */
final class EventBackgroundRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventBackgroundRepository $backgrounds;
    private string $namePrefix;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->namePrefix = 'PhpUnitBackground-' . bin2hex(random_bytes(4));
        $this->slugPrefix = 'phpunit-bg-event-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->backgrounds = new EventBackgroundRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->slugPrefix . '%']);
        $this->pdo->prepare('DELETE FROM event_backgrounds WHERE name LIKE :name')->execute(['name' => $this->namePrefix . '%']);
    }

    private function insertMinimalEvent(string $slug, ?string $backgroundId): string
    {
        $id = 'bg-evt-' . bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO events
                (id, name, slug, discipline, date, location, city, description, image_url,
                 organizer_id, organizer_name, registration_deadline, background_id)
             VALUES
                (:id, :name, :slug, :discipline, :date, :location, :city, :description, :imageUrl,
                 :organizerId, :organizerName, :registrationDeadline, :backgroundId)'
        )->execute([
            'id' => $id,
            'name' => 'PHPUnit Background Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+6 months')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => 'Opis testowy',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-organizer',
            'organizerName' => 'PHPUnit Organizer',
            'registrationDeadline' => '2026-06-01',
            'backgroundId' => $backgroundId,
        ]);

        return $id;
    }

    public function testCreateTrimsWhitespaceAndAssignsTheNextSortOrderWhenNoneIsGiven(): void
    {
        $first = $this->backgrounds->create([
            'name' => '  ' . $this->namePrefix . '-first  ',
            'imageUrl' => '  https://example.test/a.jpg  ',
        ]);
        $second = $this->backgrounds->create(['name' => $this->namePrefix . '-second', 'imageUrl' => 'https://example.test/b.jpg']);

        self::assertSame($this->namePrefix . '-first', $first['name']);
        self::assertSame('https://example.test/a.jpg', $first['imageUrl']);
        self::assertNull($first['thumbnailUrl']);
        self::assertSame($first['sortOrder'] + 1, $second['sortOrder']);
    }

    public function testUpdateOnlyTouchesFieldsPresentInThePayload(): void
    {
        $created = $this->backgrounds->create([
            'name' => $this->namePrefix . '-update',
            'imageUrl' => 'https://example.test/a.jpg',
            'thumbnailUrl' => 'https://example.test/a-thumb.jpg',
        ]);

        $updated = $this->backgrounds->update($created['id'], ['imageUrl' => 'https://example.test/b.jpg']);

        self::assertSame('https://example.test/b.jpg', $updated['imageUrl']);
        self::assertSame($created['name'], $updated['name']);
        self::assertSame($created['thumbnailUrl'], $updated['thumbnailUrl']);
    }

    public function testUpdateClearsTheThumbnailWhenGivenAnEmptyString(): void
    {
        $created = $this->backgrounds->create([
            'name' => $this->namePrefix . '-clear',
            'imageUrl' => 'https://example.test/a.jpg',
            'thumbnailUrl' => 'https://example.test/a-thumb.jpg',
        ]);

        $updated = $this->backgrounds->update($created['id'], ['thumbnailUrl' => '  ']);

        self::assertNull($updated['thumbnailUrl']);
    }

    public function testDeleteRemovesTheBackgroundPermanently(): void
    {
        $created = $this->backgrounds->create(['name' => $this->namePrefix . '-delete', 'imageUrl' => 'https://example.test/a.jpg']);

        $this->backgrounds->delete($created['id']);

        self::assertNull($this->backgrounds->find($created['id']));
    }

    public function testUsagesReturnsOnlyEventsReferencingThisBackground(): void
    {
        $used = $this->backgrounds->create(['name' => $this->namePrefix . '-used', 'imageUrl' => 'https://example.test/used.jpg']);
        $unused = $this->backgrounds->create(['name' => $this->namePrefix . '-unused', 'imageUrl' => 'https://example.test/unused.jpg']);

        $eventId = $this->insertMinimalEvent($this->slugPrefix . '-a', $used['id']);

        $usedUsages = $this->backgrounds->usages($used['id']);
        self::assertCount(1, $usedUsages);
        self::assertSame($eventId, $usedUsages[0]['id']);

        self::assertSame([], $this->backgrounds->usages($unused['id']));
    }

    public function testListOrdersBySortOrderThenName(): void
    {
        $b = $this->backgrounds->create(['name' => $this->namePrefix . '-b', 'imageUrl' => 'https://example.test/b.jpg', 'sortOrder' => 5]);
        $a = $this->backgrounds->create(['name' => $this->namePrefix . '-a', 'imageUrl' => 'https://example.test/a.jpg', 'sortOrder' => 5]);

        $ids = array_column(
            array_filter($this->backgrounds->list(), fn (array $row): bool => str_starts_with($row['name'], $this->namePrefix)),
            'id'
        );

        self::assertSame([$a['id'], $b['id']], array_values($ids));
    }
}
