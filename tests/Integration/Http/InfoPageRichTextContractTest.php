<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Admins edit info pages in a rich-text CMS editor. Text blocks flagged format "html" are
 * sanitized on write with HtmlSanitizer (same as event descriptions); legacy blocks (no format) stay
 * plain text and are served with format "text".
 */
final class InfoPageRichTextContractTest extends HttpTestCase
{
    private const DIRTY = '<h2 onclick="x()">Tytuł</h2><p onmouseover="x()">Akapit <strong>gruby</strong> <script>alert(1)</script>'
        . '<img src=x onerror="alert(2)"><a href="javascript:alert(3)">zły</a> <a href="https://example.com">dobry</a></p><ul><li>punkt</li></ul>';

    private string $prefix;

    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'http-rich-' . bin2hex(random_bytes(4));
        $this->admin = (new UserRepository($this->pdo))->create([
            'name' => 'Rich Admin', 'email' => $this->prefix . '-admin@example.test', 'password' => 'demo1234', 'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->prefix)) {
            $this->pdo->prepare('DELETE FROM info_pages WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '%']);
            $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
        }
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        return $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
    }

    /** @return array<string, mixed> */
    private function createPage(array $blocks): array
    {
        $response = $this->request('POST', '/api/v1/info-pages', [
            'title' => 'Rich', 'slug' => $this->prefix . '-' . bin2hex(random_bytes(3)), 'content' => $blocks,
        ], $this->adminHeaders());
        self::assertSame(201, $response->getStatusCode());
        return $this->json($response)['data'];
    }

    private function assertClean(string $html): void
    {
        foreach (['<script', 'alert(1)', 'onerror', 'onclick', 'onmouseover', 'javascript:', '<img'] as $bad) {
            self::assertStringNotContainsString($bad, $html);
        }
        self::assertStringContainsString('<h2>Tytuł</h2>', $html);
        self::assertStringContainsString('<strong>gruby</strong>', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('<ul><li>punkt</li></ul>', $html);
    }

    public function testPostSanitizesHtmlTextBlock(): void
    {
        $page = $this->createPage([['id' => 'b', 'type' => 'text', 'format' => 'html', 'title' => '', 'content' => self::DIRTY]]);
        self::assertSame('html', $page['content'][0]['format']);
        $this->assertClean($page['content'][0]['content']);
        $read = $this->json($this->request('GET', '/api/v1/info-pages/slug/' . $page['slug']))['data'];
        $this->assertClean($read['content'][0]['content']);
    }

    public function testPatchSanitizesHtmlTextBlock(): void
    {
        $page = $this->createPage([['id' => 'b', 'type' => 'text', 'title' => '', 'content' => 'x']]);
        $response = $this->request('PATCH', '/api/v1/info-pages/' . $page['id'], [
            'content' => [['id' => 'b', 'type' => 'text', 'format' => 'html', 'title' => '', 'content' => self::DIRTY]],
        ], $this->adminHeaders());
        self::assertSame(200, $response->getStatusCode());
        $this->assertClean($this->json($response)['data']['content'][0]['content']);
    }

    public function testLegacyBlockStaysPlainTextAndReportsTextFormat(): void
    {
        $legacy = "Linia 1\n- punkt\n**gruby** <b>nie html</b>";
        $page = $this->createPage([['id' => 'b', 'type' => 'text', 'title' => '', 'content' => $legacy]]);
        self::assertSame('text', $page['content'][0]['format']);
        self::assertSame($legacy, $page['content'][0]['content']);
    }

    public function testLegalMigrationBodiesAreHtmlFormat(): void
    {
        foreach (['regulamin', 'polityka-prywatnosci', 'rodo'] as $slug) {
            $page = $this->json($this->request('GET', '/api/v1/info-pages/slug/' . $slug))['data'];
            $body = array_values(array_filter($page['content'], static fn (array $b): bool => $b['type'] === 'text'))[0];
            self::assertSame('html', $body['format'], $slug);
        }
    }
}
