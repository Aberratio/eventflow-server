<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Tests\Support\SavepointPdo;

final class SavepointPdoTest extends TestCase
{
    private SavepointPdo $pdo;
    private string $slug;

    protected function setUp(): void
    {
        $this->pdo = new SavepointPdo();
        $this->slug = 'sp-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->pdo->rollbackOuter();
    }

    private function insertPage(\PDO $pdo, string $name): void
    {
        $pdo->prepare("INSERT INTO info_pages (id, slug, title, icon, description, content) VALUES (:id, :slug, :title, 'i', 'd', :content)")
            ->execute(['id' => 'sp-' . bin2hex(random_bytes(8)), 'slug' => $name, 'title' => 't', 'content' => '[]']);
    }

    private function pageCount(\PDO $pdo, string $slug): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM info_pages WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    public function testOuterRollbackDiscardsEverythingIncludingAppCommits(): void
    {
        $this->pdo->beginOuter();
        $this->pdo->beginTransaction();
        $this->insertPage($this->pdo, $this->slug);
        $this->pdo->commit();

        self::assertSame(1, $this->pageCount($this->pdo, $this->slug));
        self::assertFalse($this->pdo->inTransaction());

        $this->pdo->rollbackOuter();

        self::assertSame(0, $this->pageCount(Connection::fromEnv(), $this->slug));
    }

    public function testActorSavepointUndoesWritesAndStaleAppTransactions(): void
    {
        $this->pdo->beginOuter();
        $this->pdo->savepoint('a');
        $this->pdo->beginTransaction();
        $this->insertPage($this->pdo, $this->slug);
        self::assertTrue($this->pdo->inTransaction());

        $this->pdo->rollbackTo('a');

        self::assertSame(0, $this->pageCount($this->pdo, $this->slug));
        self::assertFalse($this->pdo->inTransaction());

        $this->pdo->beginTransaction();
        $this->insertPage($this->pdo, $this->slug);
        $this->pdo->commit();
        self::assertSame(1, $this->pageCount($this->pdo, $this->slug));
    }

    public function testFailedWorldBuildLeavesNoOpenTransactionOrLocks(): void
    {
        $probe = Connection::fromEnv();
        $thread = null;
        try {
            SavepointPdo::openWorld(function (SavepointPdo $pdo) use (&$thread): void {
                $thread = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
                $this->insertPage($pdo, $this->slug);
                throw new \RuntimeException('fixture build failed');
            });
            self::fail('The build exception must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('fixture build failed', $e->getMessage());
        }

        $open = $probe->prepare('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = :t');
        $open->execute(['t' => $thread]);
        self::assertSame(0, (int) $open->fetchColumn(), 'The outer transaction leaked.');

        // Lock-level proof: would fail with 1205 after 1 s if the unique-key lock leaked.
        $probe->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $probe->beginTransaction();
        $this->insertPage($probe, $this->slug);
        $probe->rollBack();
    }

    public function testCommitWithoutBeginThrowsLikePdo(): void
    {
        $this->pdo->beginOuter();

        $this->expectException(\PDOException::class);
        $this->pdo->commit();
    }
}
