<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Insurance\InsuranceRepository;
use ZmierzymyCzas\Support\Env;

/**
 * InsuranceRepository (E0.3) had zero direct test coverage before this file:
 * the only exercise of its logic was indirect, through
 * RegistrationRepositoryTest's insurance-premium pricing tests, which never
 * touch create()/update()/delete()/list() at all. Those CRUD methods feed
 * the premium formula (base_fee, age_multiplier, max_age) organizers and
 * participants pay against, so a partial-update bug here silently changes
 * money charged on every future registration that picks the affected
 * variant.
 */
final class InsuranceRepositoryTest extends TestCase
{
    private PDO $pdo;
    private InsuranceRepository $insurance;
    private string $namePrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->namePrefix = 'PhpUnitInsurance-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->insurance = new InsuranceRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->namePrefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM insurance_settings WHERE name LIKE :name')
            ->execute(['name' => $this->namePrefix . '%']);
    }

    public function testCreateStoresThePremiumFormulaFieldsAndDefaultsActiveToTrue(): void
    {
        $created = $this->insurance->create([
            'name' => $this->namePrefix . '-basic',
            'baseFee' => 12.5,
            'ageMultiplier' => 0.25,
            'maxAge' => 65,
            'infoHtml' => '<p>info</p>',
        ]);

        self::assertSame($this->namePrefix . '-basic', $created['name']);
        self::assertSame(12.5, $created['baseFee']);
        self::assertSame(0.25, $created['ageMultiplier']);
        self::assertSame(65, $created['maxAge']);
        self::assertTrue($created['active']);

        $found = $this->insurance->find($created['id']);
        self::assertSame($created, $found);
    }

    public function testUpdateOnlyTouchesFieldsPresentInThePayload(): void
    {
        $created = $this->insurance->create([
            'name' => $this->namePrefix . '-partial',
            'baseFee' => 20.0,
            'ageMultiplier' => 0.5,
            'maxAge' => 70,
        ]);

        // Only baseFee is being changed — every other field, including the
        // ones update()'s $map array knows about, must survive untouched.
        $updated = $this->insurance->update($created['id'], ['baseFee' => 30.0]);

        self::assertSame(30.0, $updated['baseFee']);
        self::assertSame(0.5, $updated['ageMultiplier']);
        self::assertSame(70, $updated['maxAge']);
        self::assertSame($created['name'], $updated['name']);
        self::assertTrue($updated['active']);
    }

    public function testUpdateCanExplicitlyDeactivateAVariant(): void
    {
        $created = $this->insurance->create(['name' => $this->namePrefix . '-toggle']);
        self::assertTrue($created['active']);

        $deactivated = $this->insurance->update($created['id'], ['active' => false]);

        self::assertFalse($deactivated['active']);
    }

    public function testDeleteRemovesTheVariantPermanently(): void
    {
        $created = $this->insurance->create(['name' => $this->namePrefix . '-delete-me']);

        $this->insurance->delete($created['id']);

        self::assertNull($this->insurance->find($created['id']));
    }

    public function testListOrdersBySortOrderThenId(): void
    {
        $second = $this->insurance->create(['name' => $this->namePrefix . '-b', 'sortOrder' => 2]);
        $first = $this->insurance->create(['name' => $this->namePrefix . '-a', 'sortOrder' => 1]);

        $ids = array_column(
            array_filter($this->insurance->list(), fn (array $row): bool => str_starts_with($row['name'], $this->namePrefix)),
            'id'
        );

        self::assertSame([$first['id'], $second['id']], array_values($ids));
    }

    /**
     * `updateSettings()` is a thin read/write on the *first* catalog row
     * (E0.3's documented backward-compatibility seam). Confirms the merge
     * semantics: a partial payload only overwrites the keys it sends, and
     * the identity of the underlying row is never rotated.
     */
    public function testUpdateSettingsMergesPartialPayloadIntoTheFirstCatalogRow(): void
    {
        $before = $this->insurance->getSettings();

        $updated = $this->insurance->updateSettings(['baseFee' => 99.0]);

        self::assertSame(99.0, $updated['baseFee']);
        self::assertSame($before['ageMultiplier'], $updated['ageMultiplier']);
        self::assertSame($before['maxAge'], $updated['maxAge']);

        // Restore, so this test doesn't leave global insurance settings mutated
        // for whatever real row happened to be "first" in this environment.
        $this->insurance->updateSettings([
            'baseFee' => $before['baseFee'],
            'ageMultiplier' => $before['ageMultiplier'],
            'maxAge' => $before['maxAge'],
            'infoHtml' => $before['infoHtml'],
        ]);
    }
}
