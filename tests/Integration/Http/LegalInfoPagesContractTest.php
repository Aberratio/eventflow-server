<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Support\HtmlSanitizer;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * The storefront footer links /info/regulamin, /info/polityka-prywatnosci and /info/rodo;
 * migration 080 seeds those three CMS pages (published, not duplicated in the footer list) so the
 * links resolve. Their rich-text body must already be what HtmlSanitizer would store, so the first
 * admin edit does not silently rewrite it.
 */
final class LegalInfoPagesContractTest extends HttpTestCase
{
    private const MIGRATION = __DIR__ . '/../../../database/migrations/080_b06_legal_info_pages.sql';

    private const NOTE = 'Treść tymczasowa przeniesiona z dotychczasowej strony zmierzymyczas.pl – do weryfikacji i uzupełnienia przez administratora.';

    /** @return array<string, array{string}> */
    public static function slugs(): array
    {
        return ['regulamin' => ['regulamin'], 'polityka' => ['polityka-prywatnosci'], 'rodo' => ['rodo']];
    }

    /** @dataProvider slugs */
    public function testLegalPageIsServedPublishedWithSanitizedBody(string $slug): void
    {
        $response = $this->request('GET', '/api/v1/info-pages/slug/' . $slug);
        self::assertSame(200, $response->getStatusCode());
        $page = $this->json($response)['data'];

        self::assertIsArray($page, 'info page "' . $slug . '" must exist');
        self::assertSame($slug, $page['slug']);
        self::assertTrue($page['published']);
        self::assertFalse($page['showInFooter'], 'the footer already has a hard-coded legal section');
        self::assertNotSame('', trim((string) $page['title']));

        $texts = array_values(array_filter($page['content'], static fn (array $b): bool => $b['type'] === 'text'));
        self::assertNotEmpty($texts);
        foreach ($texts as $block) {
            self::assertStringStartsWith('<p>' . self::NOTE . '</p>', $block['content']);
            self::assertSame($block['content'], HtmlSanitizer::clean($block['content']), 'body must be sanitizer-stable');
        }
    }

    public function testPolicyKeepsHeadingsListsAndLinks(): void
    {
        $page = $this->json($this->request('GET', '/api/v1/info-pages/slug/polityka-prywatnosci'))['data'];
        $html = implode('', array_column($page['content'], 'content'));

        self::assertStringContainsString('<h2>', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<strong>', $html);
        self::assertStringContainsString('href="mailto:', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testMigrationIsIdempotentAndDoesNotOverwriteAdminEdits(): void
    {
        $this->pdo->prepare("UPDATE info_pages SET title = 'Edytowany regulamin' WHERE slug = 'regulamin'")->execute();

        $sql = (string) preg_replace('/^--.*$/m', '', (string) file_get_contents(self::MIGRATION));
        foreach (preg_split('/;\s*\n\s*\n/', trim($sql)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec(rtrim($statement, ';'));
            }
        }

        $count = $this->pdo->query("SELECT COUNT(*) FROM info_pages WHERE slug IN ('regulamin','polityka-prywatnosci','rodo')")->fetchColumn();
        self::assertSame(3, (int) $count);
        self::assertSame('Edytowany regulamin', $this->pdo->query("SELECT title FROM info_pages WHERE slug = 'regulamin'")->fetchColumn());
    }
}
