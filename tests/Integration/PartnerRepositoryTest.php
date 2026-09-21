<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Cms\PartnerRepository;
use ZmierzymyCzas\Support\Env;

/**
 * TD.16: PartnerRepository had zero direct test coverage before this file.
 * Covers create()'s trimming/default-sort-order/boolean-coercion, update()'s
 * "only touch what's present" merge semantics including clearing a nullable
 * field back to null via an empty string, and that featured() actually
 * filters (not just orders) by the `featured` flag.
 */
final class PartnerRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PartnerRepository $partners;
    private string $namePrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->namePrefix = 'PhpUnitPartner-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->partners = new PartnerRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->namePrefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM partners WHERE name LIKE :name')
            ->execute(['name' => $this->namePrefix . '%']);
    }

    public function testCreateTrimsWhitespaceAndDefaultsFeaturedToFalse(): void
    {
        $created = $this->partners->create([
            'name' => '  ' . $this->namePrefix . '-trim  ',
            'logoUrl' => '  https://example.test/logo.png  ',
        ]);

        self::assertSame($this->namePrefix . '-trim', $created['name']);
        self::assertSame('https://example.test/logo.png', $created['logoUrl']);
        self::assertFalse($created['featured']);
        self::assertNull($created['logoDarkUrl']);
        self::assertNull($created['websiteUrl']);
    }

    public function testCreateAssignsTheNextSortOrderWhenNoneIsGiven(): void
    {
        $first = $this->partners->create(['name' => $this->namePrefix . '-first']);
        $second = $this->partners->create(['name' => $this->namePrefix . '-second']);

        $firstOrder = (int) $this->pdo->query(
            "SELECT sort_order FROM partners WHERE id = '{$first['id']}'"
        )->fetchColumn();
        $secondOrder = (int) $this->pdo->query(
            "SELECT sort_order FROM partners WHERE id = '{$second['id']}'"
        )->fetchColumn();

        self::assertSame($firstOrder + 1, $secondOrder);
    }

    public function testFeaturedOnlyReturnsPartnersFlaggedFeatured(): void
    {
        $featured = $this->partners->create(['name' => $this->namePrefix . '-featured-a', 'featured' => true]);
        $this->partners->create(['name' => $this->namePrefix . '-not-featured', 'featured' => false]);

        $result = array_filter(
            $this->partners->featured(),
            fn (array $row): bool => str_starts_with($row['name'], $this->namePrefix)
        );

        self::assertSame([$featured['id']], array_column($result, 'id'));
    }

    public function testUpdateOnlyTouchesFieldsPresentInThePayload(): void
    {
        $created = $this->partners->create([
            'name' => $this->namePrefix . '-update',
            'logoUrl' => 'https://example.test/a.png',
            'websiteUrl' => 'https://example.test',
            'featured' => true,
        ]);

        $updated = $this->partners->update($created['id'], ['logoUrl' => 'https://example.test/b.png']);

        self::assertSame('https://example.test/b.png', $updated['logoUrl']);
        self::assertSame($created['name'], $updated['name']);
        self::assertSame($created['websiteUrl'], $updated['websiteUrl']);
        self::assertTrue($updated['featured']);
    }

    public function testUpdateClearsANullableFieldWhenGivenAnEmptyString(): void
    {
        $created = $this->partners->create([
            'name' => $this->namePrefix . '-clear',
            'logoUrl' => 'https://example.test/a.png',
            'websiteUrl' => 'https://example.test',
        ]);
        self::assertSame('https://example.test', $created['websiteUrl']);

        $updated = $this->partners->update($created['id'], ['websiteUrl' => '   ']);

        self::assertNull($updated['websiteUrl']);
    }

    public function testDeleteRemovesThePartnerPermanently(): void
    {
        $created = $this->partners->create(['name' => $this->namePrefix . '-delete']);

        $this->partners->delete($created['id']);

        self::assertNull($this->partners->find($created['id']));
    }

    public function testListOrdersBySortOrderThenName(): void
    {
        $b = $this->partners->create(['name' => $this->namePrefix . '-b', 'sortOrder' => 5]);
        $a = $this->partners->create(['name' => $this->namePrefix . '-a', 'sortOrder' => 5]);

        $ids = array_column(
            array_filter($this->partners->list(), fn (array $row): bool => str_starts_with($row['name'], $this->namePrefix)),
            'id'
        );

        // Same sort_order for both, so the tiebreak must be alphabetical by name.
        self::assertSame([$a['id'], $b['id']], array_values($ids));
    }
}
