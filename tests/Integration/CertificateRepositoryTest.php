<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Certificate\CertificateRepository;
use ZmierzymyCzas\Support\Env;

/**
 * TD.16: CertificateRepository had zero direct test coverage before this file.
 * Certificates are participant-facing proof of a race result (finish time,
 * position, a certificate number meant to be unique/verifiable), so the two
 * things worth pinning down are (a) create() always mints a well-formed,
 * unique certificate_number rather than ever colliding or leaking a
 * malformed one, and (b) byUser()/find() never leak another participant's
 * certificate.
 */
final class CertificateRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CertificateRepository $certificates;
    private string $userPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->userPrefix = 'phpunit-cert-user-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->certificates = new CertificateRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->userPrefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM certificates WHERE user_id LIKE :userId')
            ->execute(['userId' => $this->userPrefix . '%']);
    }

    public function testCreatePersistsAllFieldsAndMintsAUniqueCertificateNumber(): void
    {
        $created = $this->certificates->create([
            'resultId' => 'result-1',
            'userId' => $this->userPrefix . '-a',
            'eventId' => 'event-1',
            'eventName' => 'PHPUnit Marathon',
            'distanceName' => '10 km',
            'pdfUrl' => 'https://example.test/cert.pdf',
            'finishTime' => '00:45:12',
            'position' => 7,
        ]);

        self::assertSame('result-1', $created['resultId']);
        self::assertSame($this->userPrefix . '-a', $created['userId']);
        self::assertSame('event-1', $created['eventId']);
        self::assertSame('PHPUnit Marathon', $created['eventName']);
        self::assertSame('10 km', $created['distanceName']);
        self::assertSame('https://example.test/cert.pdf', $created['pdfUrl']);
        self::assertSame('00:45:12', $created['finishTime']);
        self::assertSame(7, $created['position']);
        self::assertMatchesRegularExpression(
            '/^CERT-' . date('Y') . '-[0-9A-F]{8}$/',
            $created['certificateNumber']
        );

        $found = $this->certificates->find($created['id']);
        self::assertSame($created, $found);
    }

    public function testCreateDefaultsOptionalFieldsToNullWhenOmitted(): void
    {
        $created = $this->certificates->create([
            'resultId' => 'result-2',
            'userId' => $this->userPrefix . '-b',
            'eventId' => 'event-2',
            'eventName' => 'PHPUnit 5K',
            'distanceName' => '5 km',
        ]);

        self::assertNull($created['pdfUrl']);
        self::assertNull($created['finishTime']);
        self::assertNull($created['position']);
    }

    public function testCreateGeneratesADistinctCertificateNumberOnEachCall(): void
    {
        $first = $this->certificates->create([
            'resultId' => 'result-3',
            'userId' => $this->userPrefix . '-c',
            'eventId' => 'event-3',
            'eventName' => 'PHPUnit Half',
            'distanceName' => '21 km',
        ]);
        $second = $this->certificates->create([
            'resultId' => 'result-4',
            'userId' => $this->userPrefix . '-c',
            'eventId' => 'event-3',
            'eventName' => 'PHPUnit Half',
            'distanceName' => '21 km',
        ]);

        self::assertNotSame($first['certificateNumber'], $second['certificateNumber']);
    }

    public function testFindReturnsNullForAnUnknownId(): void
    {
        self::assertNull($this->certificates->find('does-not-exist'));
    }

    public function testByUserOnlyReturnsThatUsersCertificatesOrderedByIssuedAtDescending(): void
    {
        $ownUserId = $this->userPrefix . '-owner';
        $otherUserId = $this->userPrefix . '-other';

        $older = $this->certificates->create([
            'resultId' => 'result-older',
            'userId' => $ownUserId,
            'eventId' => 'event-5',
            'eventName' => 'PHPUnit Older Race',
            'distanceName' => '10 km',
        ]);
        $newer = $this->certificates->create([
            'resultId' => 'result-newer',
            'userId' => $ownUserId,
            'eventId' => 'event-6',
            'eventName' => 'PHPUnit Newer Race',
            'distanceName' => '10 km',
        ]);
        // A certificate belonging to a different user must never surface in
        // this user's list, however it's sorted.
        $this->certificates->create([
            'resultId' => 'result-other',
            'userId' => $otherUserId,
            'eventId' => 'event-7',
            'eventName' => 'PHPUnit Other Race',
            'distanceName' => '10 km',
        ]);

        // issued_at is a DB-side CURRENT_TIMESTAMP default with second
        // resolution, so both rows could otherwise land in the same second;
        // force a real ordering to actually exercise ORDER BY issued_at DESC.
        $this->pdo->prepare('UPDATE certificates SET issued_at = :issuedAt WHERE id = :id')
            ->execute(['issuedAt' => '2020-01-01 00:00:00', 'id' => $older['id']]);
        $this->pdo->prepare('UPDATE certificates SET issued_at = :issuedAt WHERE id = :id')
            ->execute(['issuedAt' => '2021-01-01 00:00:00', 'id' => $newer['id']]);

        $result = $this->certificates->byUser($ownUserId);

        self::assertCount(2, $result);
        self::assertSame([$newer['id'], $older['id']], array_column($result, 'id'));
    }
}
