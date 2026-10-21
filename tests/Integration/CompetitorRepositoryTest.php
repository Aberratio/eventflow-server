<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Support\Env;

/**
 * Kod tożsamości QR (`zqr_`) na Zawodniku — mirror
 * RegistrationRepositoryTest's start-code coverage, just for the
 * competitor-owned code instead of the registration-owned one.
 */
final class CompetitorRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CompetitorRepository $competitors;
    private string $namePrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->namePrefix = 'PhpUnitIdentityCode-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->competitors = new CompetitorRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->namePrefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')
            ->execute(['last' => $this->namePrefix]);
    }

    /** Every Zawodnik gets a persisted `zqr_`-prefixed, 48-hex-char identity code — no null, no separate step. */
    public function testCreateGeneratesPersistedIdentityCode(): void
    {
        $competitor = $this->competitors->create([
            'firstName' => 'Zosia',
            'lastName' => $this->namePrefix,
            'birthDate' => '2018-05-01',
        ]);

        self::assertMatchesRegularExpression('/^zqr_[a-f0-9]{48}$/', (string) $competitor['identityCode']);

        $found = $this->competitors->find($competitor['id']);
        self::assertSame($competitor['identityCode'], $found['identityCode']);
    }

    /** Two Zawodnicy never collide on the same identity code. */
    public function testCreateGeneratesDistinctIdentityCodesPerCompetitor(): void
    {
        $first = $this->competitors->create(['firstName' => 'Ala', 'lastName' => $this->namePrefix]);
        $second = $this->competitors->create(['firstName' => 'Bea', 'lastName' => $this->namePrefix]);

        self::assertNotSame($first['identityCode'], $second['identityCode']);
    }

    /** An unrelated profile update must not rotate the identity code. */
    public function testUnrelatedUpdateDoesNotRotateIdentityCode(): void
    {
        $competitor = $this->competitors->create(['firstName' => 'Ola', 'lastName' => $this->namePrefix]);

        $updated = $this->competitors->update($competitor['id'], ['phone' => '123456789']);

        self::assertSame($competitor['identityCode'], $updated['identityCode']);
    }

    /** Rotation: overwrites the old code with a fresh one, no history kept. */
    public function testRegenerateIdentityCodeRotatesToADistinctNewCode(): void
    {
        $competitor = $this->competitors->create(['firstName' => 'Kasia', 'lastName' => $this->namePrefix]);
        $oldCode = $competitor['identityCode'];

        $rotated = $this->competitors->regenerateIdentityCode($competitor['id']);

        self::assertNotNull($rotated);
        self::assertMatchesRegularExpression('/^zqr_[a-f0-9]{48}$/', (string) $rotated['identityCode']);
        self::assertNotSame($oldCode, $rotated['identityCode']);

        $found = $this->competitors->find($competitor['id']);
        self::assertSame($rotated['identityCode'], $found['identityCode']);
    }

    public function testRegenerateIdentityCodeReturnsNullForUnknownCompetitor(): void
    {
        self::assertNull($this->competitors->regenerateIdentityCode('does-not-exist'));
    }

    /**
     * The anonymization flag itself — defaults to false
     * (an existing profile's behavior never changes on its own), and the
     * self-service toggle flips it in either direction, is persisted, and
     * touches nothing else on the profile.
     */
    public function testNewCompetitorDefaultsToNotAnonymized(): void
    {
        $competitor = $this->competitors->create(['firstName' => 'Ela', 'lastName' => $this->namePrefix]);

        self::assertFalse((bool) $competitor['isAnonymized']);
    }

    public function testSetAnonymizationTogglesAndPersists(): void
    {
        $competitor = $this->competitors->create(['firstName' => 'Fela', 'lastName' => $this->namePrefix]);

        $anonymized = $this->competitors->setAnonymization($competitor['id'], true);
        self::assertNotNull($anonymized);
        self::assertTrue((bool) $anonymized['isAnonymized']);
        self::assertSame($competitor['firstName'], $anonymized['firstName']);

        $found = $this->competitors->find($competitor['id']);
        self::assertTrue((bool) $found['isAnonymized']);

        $unAnonymized = $this->competitors->setAnonymization($competitor['id'], false);
        self::assertFalse((bool) $unAnonymized['isAnonymized']);
    }

    public function testSetAnonymizationReturnsNullForUnknownCompetitor(): void
    {
        self::assertNull($this->competitors->setAnonymization('does-not-exist', true));
    }
}
