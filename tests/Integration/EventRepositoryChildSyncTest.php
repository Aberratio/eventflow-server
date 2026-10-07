<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\InputValidationException;

/**
 * PP.59: transaction + trusted-mode + logging behaviour of EventRepository's child sync.
 */
final class EventRepositoryChildSyncTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private string $prefix;
    private bool $triggerCreated = false;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->prefix = 'pp59r-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->purge();
            $this->events = new EventRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->dropTrigger();
        $this->purge();
    }

    public function testUpdateIsAtomicWhenChildSyncFails(): void
    {
        $event = $this->events->create($this->payload('atomic', [
            'distances' => [['id' => 'pp59r-d1', 'name' => 'Original', 'unit' => 'km']],
            'galleryAlbums' => [],
        ]));
        $id = (string) $event['id'];
        $distanceId = (string) $event['distances'][0]['id'];

        $this->pdo->exec("CREATE TRIGGER pp59_fail_album BEFORE INSERT ON event_gallery_albums FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pp59 forced failure'");
        $this->triggerCreated = true;

        try {
            $this->events->update($id, [
                'name' => 'Changed name',
                'distances' => [['id' => $distanceId, 'name' => 'Changed distance', 'unit' => 'km'], ['name' => 'Added', 'unit' => 'km']],
                'galleryAlbums' => [['name' => 'Album', 'url' => 'https://example.test/a', 'platform' => 'google']],
            ]);
            self::fail('expected the forced failure');
        } catch (\PDOException $e) {
            self::assertStringContainsString('pp59 forced failure', $e->getMessage());
        } finally {
            $this->dropTrigger();
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('PHPUnit PP59 atomic', $this->scalar('SELECT name FROM events WHERE id = :id', ['id' => $id]));
        $stmt = $this->pdo->prepare('SELECT id, name FROM event_distances WHERE event_id = :id');
        $stmt->execute(['id' => $id]);
        self::assertSame([['id' => $distanceId, 'name' => 'Original']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM event_gallery_albums WHERE event_id = :id', ['id' => $id]));
    }

    public function testValidationFailureOnUpdateWritesNothing(): void
    {
        $event = $this->events->create($this->payload('novalid', ['distances' => [['name' => 'Original', 'unit' => 'km']]]));
        $id = (string) $event['id'];

        try {
            $this->events->update($id, ['name' => 'Should not stick', 'distances' => [['name' => 'x', 'unit' => 'bad']]]);
            self::fail('expected InputValidationException');
        } catch (InputValidationException $e) {
            self::assertSame('distances[0].unit', $e->field);
        }

        self::assertSame('PHPUnit PP59 novalid', $this->scalar('SELECT name FROM events WHERE id = :id', ['id' => $id]));
    }

    public function testUpdateInsideAnOuterTransactionDoesNotNest(): void
    {
        $event = $this->events->create($this->payload('outer', ['distances' => [['name' => 'Original', 'unit' => 'km']]]));
        $id = (string) $event['id'];

        $this->pdo->beginTransaction();
        try {
            $this->events->update($id, ['distances' => [['name' => 'In outer', 'unit' => 'km']]]);
            self::assertTrue($this->pdo->inTransaction(), 'the repository must not commit a transaction it did not open');
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame('Original', $this->scalar('SELECT name FROM event_distances WHERE event_id = :id', ['id' => $id]));
    }

    public function testTrustedCreateKeepsFixtureIdsAndCounters(): void
    {
        $event = $this->events->create($this->payload('trusted', [
            'distances' => [['id' => 'pp59r-d1', 'name' => '10 km', 'unit' => 'km', 'registeredCount' => 4]],
            'discountCodes' => [['id' => 'pp59r-dc1', 'code' => 'FIX', 'discountType' => 'fixed', 'discountValue' => 5, 'usedCount' => 2, 'usedBy' => [['userId' => 'u1']]]],
            'merchandise' => [['id' => 'pp59r-m1', 'name' => 'Koszulka', 'price' => 10, 'distanceIds' => ['pp59r-d1']]],
        ]));
        $id = (string) $event['id'];

        self::assertSame('pp59r-d1', $this->scalar('SELECT id FROM event_distances WHERE event_id = :id', ['id' => $id]));
        self::assertSame(4, (int) $this->scalar('SELECT registered_count FROM event_distances WHERE event_id = :id', ['id' => $id]));
        self::assertSame('pp59r-dc1', $this->scalar('SELECT id FROM event_discount_codes WHERE event_id = :id', ['id' => $id]));
        self::assertSame(2, (int) $this->scalar('SELECT used_count FROM event_discount_codes WHERE event_id = :id', ['id' => $id]));
        self::assertSame([['userId' => 'u1']], json_decode((string) $this->scalar('SELECT used_by FROM event_discount_codes WHERE event_id = :id', ['id' => $id]), true));
        self::assertSame(['pp59r-d1'], json_decode((string) $this->scalar('SELECT distance_ids FROM event_merchandise WHERE event_id = :id', ['id' => $id]), true));
    }

    public function testTrustedCreateWithoutIdGeneratesUuid(): void
    {
        $event = $this->events->create($this->payload('trusted-noid', ['distances' => [['name' => '10 km', 'unit' => 'km']]]));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $event['distances'][0]['id']);
    }

    public function testTrustedCreateRejectsOverlongId(): void
    {
        $this->expectException(InputValidationException::class);
        $this->events->create($this->payload('trusted-long', [
            'distances' => [['id' => str_repeat('a', 37), 'name' => '10 km', 'unit' => 'km']],
        ]));
    }

    public function testUnknownKeysAreLoggedWithoutValues(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $repo = new EventRepository($this->pdo, $logger);
        $event = $repo->create($this->payload('log'));

        $repo->update((string) $event['id'], [
            'distances' => [
                ['name' => '10 km', 'unit' => 'km', 'foo' => 'SECRET-VALUE-1', 'paidCount' => 3],
                ['name' => '5 km', 'unit' => 'km', 'foo' => 'SECRET-VALUE-2'],
            ],
            'paymentMethods' => [['name' => 'P']],
        ]);

        $notices = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'notice'));
        self::assertCount(1, $notices, (string) json_encode($logger->records));
        self::assertSame('distances', $notices[0]['context']['collection']);
        self::assertSame(['foo'], $notices[0]['context']['keys']);
        self::assertStringNotContainsString('SECRET-VALUE', (string) json_encode($logger->records));
    }

    // ---- helpers ---------------------------------------------------------------------

    private function dropTrigger(): void
    {
        if ($this->triggerCreated) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS pp59_fail_album');
            $this->triggerCreated = false;
        }
    }

    private function purge(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => 'pp59r-%']);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $id]);
            foreach (['event_distances', 'event_merchandise', 'event_discount_codes', 'event_payment_methods',
                'event_gallery_albums', 'event_documents', 'event_result_documents', 'registrations'] as $table) {
                $this->pdo->prepare("DELETE FROM $table WHERE event_id = :id")->execute(['id' => $id]);
            }
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(string $suffix, array $overrides = []): array
    {
        return array_replace([
            'name' => 'PHPUnit PP59 ' . $suffix,
            'slug' => $this->prefix . '-' . $suffix,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+18 months')),
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
        ], $overrides);
    }
}
