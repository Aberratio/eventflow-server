<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Domain\Cms\InfoPageRepository;
use ZmierzymyCzas\Support\Env;

/**
 * TD.16: InfoPageRepository had zero direct test coverage before this file.
 * Covers the JSON `content` round-trip, the boolean/int hydration performed
 * by hydrate(), update()'s "only touch what's present" merge semantics, and
 * reorder()'s index-becomes-sort_order behaviour — the parts of this class
 * where a regression would silently corrupt public CMS content rather than
 * throw.
 */
final class InfoPageRepositoryTest extends TestCase
{
    private PDO $pdo;
    private InfoPageRepository $infoPages;
    private string $slugPrefix;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../../.env');
        $this->slugPrefix = 'phpunit-info-page-' . bin2hex(random_bytes(4));

        try {
            $this->pdo = Connection::fromEnv();
            $this->infoPages = new InfoPageRepository($this->pdo);
        } catch (\Throwable $e) {
            self::markTestSkipped('Database is not available for integration tests: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->slugPrefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM info_pages WHERE slug LIKE :slug')
            ->execute(['slug' => $this->slugPrefix . '%']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $slug = $overrides['slug'] ?? $this->slugPrefix;

        return array_replace([
            'title' => 'PHPUnit Info Page',
            'slug' => $slug,
            'icon' => 'info',
            'description' => 'A page created by tests.',
            'content' => ['blocks' => [['type' => 'paragraph', 'text' => 'Hello']]],
            'published' => false,
            'showInFooter' => false,
            'showInHeader' => false,
        ], $overrides);
    }

    public function testCreatePersistsContentAsJsonAndHydratesBooleansAndDefaultOrder(): void
    {
        $created = $this->infoPages->create($this->payload());

        self::assertSame(['blocks' => [['type' => 'paragraph', 'text' => 'Hello']]], $created['content']);
        self::assertFalse($created['published']);
        self::assertFalse($created['showInFooter']);
        self::assertFalse($created['showInHeader']);
        self::assertSame(99, $created['order']);

        $found = $this->infoPages->find($created['id']);
        self::assertSame($created, $found);
    }

    public function testFindBySlugReturnsTheMatchingPageAndNullForAnUnknownSlug(): void
    {
        $created = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-bySlug']));

        self::assertSame($created, $this->infoPages->findBySlug($this->slugPrefix . '-bySlug'));
        self::assertNull($this->infoPages->findBySlug($this->slugPrefix . '-does-not-exist'));
    }

    public function testUpdateOnlyTouchesFieldsPresentInThePayload(): void
    {
        $created = $this->infoPages->create($this->payload([
            'slug' => $this->slugPrefix . '-update',
            'published' => false,
            'showInFooter' => true,
        ]));

        $updated = $this->infoPages->update($created['id'], ['published' => true]);

        self::assertTrue($updated['published']);
        // showInFooter wasn't in the payload, so it must survive untouched.
        self::assertTrue($updated['showInFooter']);
        self::assertSame($created['title'], $updated['title']);
        self::assertSame($created['content'], $updated['content']);
    }

    public function testUpdateReEncodesContentWhenProvided(): void
    {
        $created = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-content']));

        $updated = $this->infoPages->update($created['id'], [
            'content' => ['blocks' => [['type' => 'heading', 'text' => 'Changed']]],
        ]);

        self::assertSame(['blocks' => [['type' => 'heading', 'text' => 'Changed']]], $updated['content']);
    }

    public function testDeleteRemovesThePagePermanently(): void
    {
        $created = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-delete']));

        $this->infoPages->delete($created['id']);

        self::assertNull($this->infoPages->find($created['id']));
    }

    public function testListOrdersBySortOrderAscending(): void
    {
        $second = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-b', 'order' => 2]));
        $first = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-a', 'order' => 1]));

        $ids = array_column(
            array_filter($this->infoPages->list(), fn (array $row): bool => str_starts_with($row['slug'], $this->slugPrefix)),
            'id'
        );

        self::assertSame([$first['id'], $second['id']], array_values($ids));
    }

    public function testReorderSetsSortOrderFromArrayIndexOrder(): void
    {
        $a = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-reorder-a', 'order' => 1]));
        $b = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-reorder-b', 'order' => 2]));
        $c = $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-reorder-c', 'order' => 3]));

        // Reverse the order entirely: c first, then a, then b.
        $this->infoPages->reorder([$c['id'], $a['id'], $b['id']]);

        self::assertSame(0, $this->infoPages->find($c['id'])['order']);
        self::assertSame(1, $this->infoPages->find($a['id'])['order']);
        self::assertSame(2, $this->infoPages->find($b['id'])['order']);
    }

    public function testCreateRejectsADuplicateSlug(): void
    {
        $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-dup']));

        $this->expectException(\PDOException::class);
        $this->infoPages->create($this->payload(['slug' => $this->slugPrefix . '-dup']));
    }
}
