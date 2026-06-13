<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

final class RegistrationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpreg-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->deletePhpUnitEvents();
            $this->events = new EventRepository($this->pdo);
            $this->registrations = new RegistrationRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->deletePhpUnitEvents();
    }

    public function testCreateAppliesFixedDecimalDiscountAndTracksUsage(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-fixed',
            'discountCodes' => [
                [
                    'id' => 'phpunit-fixed-code',
                    'code' => 'SAVE2550',
                    'discountType' => 'fixed',
                    'discountValue' => 25.50,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, 'SAVE2550'));

        self::assertSame('SAVE2550', $registration['discountCode']);
        self::assertEquals(25.50, (float) $registration['discountAmount']);
        self::assertEquals(54.50, (float) $registration['pricePaid']);

        $code = $this->rawDiscountCode('phpunit-fixed-code');
        self::assertSame(1, (int) $code['used_count']);
        $usedBy = json_decode((string) $code['used_by'], true);
        self::assertSame($registration['id'], $usedBy[0]['registrationId']);
        self::assertSame('10 km', $usedBy[0]['distanceName']);
    }

    public function testFixedDiscountHigherThanDistancePriceCreatesZeroStartFee(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-free',
            'discountCodes' => [
                [
                    'id' => 'phpunit-free-code',
                    'code' => 'FREE200',
                    'discountType' => 'fixed',
                    'discountValue' => 200,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
        ]));

        $registration = $this->registrations->create($this->registrationPayload($event, 'FREE200'));

        self::assertEquals(80.00, (float) $registration['discountAmount']);
        self::assertEquals(0.00, (float) $registration['pricePaid']);
    }

    public function testRejectsDiscountForDifferentDistance(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-distance',
            'discountCodes' => [
                [
                    'id' => 'phpunit-distance-code',
                    'code' => 'ONLY5K',
                    'discountType' => 'fixed',
                    'discountValue' => 10,
                    'usedCount' => 0,
                    'distanceIds' => [$this->slugPrefix . '-distance-5k'],
                    'active' => true,
                ],
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('discount_code_distance_mismatch');

        $this->registrations->create($this->registrationPayload($event, 'ONLY5K'));
    }

    public function testRejectsDiscountAfterMaxUses(): void
    {
        $event = $this->events->create($this->payload([
            'slug' => $this->slugPrefix . '-limit',
            'discountCodes' => [
                [
                    'id' => 'phpunit-limit-code',
                    'code' => 'ONCE',
                    'discountType' => 'percent',
                    'discountValue' => 50,
                    'maxUses' => 1,
                    'usedCount' => 0,
                    'distanceIds' => [],
                    'active' => true,
                ],
            ],
        ]));

        $this->registrations->create($this->registrationPayload($event, 'ONCE', 'user-one'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('discount_code_used_up');

        $this->registrations->create($this->registrationPayload($event, 'ONCE', 'user-two'));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->slugPrefix);
        return array_replace_recursive([
            'name' => 'PHPUnit Registration Event',
            'slug' => $slug,
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
                [
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
                ],
                [
                    'id' => $slug . '-5k',
                    'name' => '5 km',
                    'length' => 5,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 50,
                    'routeFiles' => [],
                ],
            ],
        ], $overrides);
    }

    /** @param array<string, mixed> $event */
    private function registrationPayload(array $event, string $code, string $userId = 'phpunit-user'): array
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
            'discountCode' => $code,
            'merchandiseOrders' => [],
        ];
    }

    private function rawDiscountCode(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_discount_codes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: [];
    }

    private function deletePhpUnitEvents(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => 'phpreg-%']);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            $registrationIds = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $registrationIds->execute(['id' => $id]);
            foreach (($registrationIds->fetchAll(PDO::FETCH_COLUMN) ?: []) as $registrationId) {
                $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
    }
}
