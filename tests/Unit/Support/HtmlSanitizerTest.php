<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Support\HtmlSanitizer;

/**
 * Second line of defence for stored XSS in event/insurance rich text.
 * Allowlist must stay in sync with client src/lib/sanitizeHtml.ts.
 */
final class HtmlSanitizerTest extends TestCase
{
    /** @return array<string, array<int, string>> */
    public static function dangerousMarkupProvider(): array
    {
        return [
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'script tag' => ['<script>alert(1)</script>'],
            'svg onload' => ['<svg onload=alert(1)></svg>'],
            'inline onclick' => ['<p onclick="x">a</p>'],
            'iframe' => ['<iframe src="https://evil"></iframe>'],
        ];
    }

    #[DataProvider('dangerousMarkupProvider')]
    public function testStripsEventHandlersAndScripts(string $input): void
    {
        $out = HtmlSanitizer::clean($input);

        self::assertStringNotContainsStringIgnoringCase('<script', $out);
        self::assertStringNotContainsStringIgnoringCase('<img', $out);
        self::assertStringNotContainsStringIgnoringCase('<svg', $out);
        self::assertStringNotContainsStringIgnoringCase('<iframe', $out);
        self::assertStringNotContainsStringIgnoringCase('onerror', $out);
        self::assertStringNotContainsStringIgnoringCase('onload', $out);
        self::assertStringNotContainsStringIgnoringCase('onclick', $out);
    }

    /** @return array<string, array<int, string>> */
    public static function dangerousSchemeProvider(): array
    {
        return [
            'javascript' => ['<a href="javascript:alert(1)">x</a>'],
            'JAVASCRIPT uppercase' => ['<a href="JAVASCRIPT:alert(1)">x</a>'],
            'data uri' => ['<a href="data:text/html,alert(1)">x</a>'],
            'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
        ];
    }

    #[DataProvider('dangerousSchemeProvider')]
    public function testRemovesDangerousUrlSchemes(string $input): void
    {
        $out = HtmlSanitizer::clean($input);

        self::assertStringNotContainsStringIgnoringCase('javascript:', $out);
        self::assertStringNotContainsStringIgnoringCase('data:', $out);
        self::assertStringNotContainsStringIgnoringCase('vbscript:', $out);
    }

    public function testKeepsAllowedSchemes(): void
    {
        self::assertStringContainsString(
            'href="https://example.pl"',
            HtmlSanitizer::clean('<a href="https://example.pl">x</a>'),
        );
        self::assertStringContainsString(
            'href="http://example.pl"',
            HtmlSanitizer::clean('<a href="http://example.pl">x</a>'),
        );
        self::assertStringContainsString(
            'href="mailto:a@example.pl"',
            HtmlSanitizer::clean('<a href="mailto:a@example.pl">x</a>'),
        );
        self::assertStringContainsString(
            'href="tel:+48123456789"',
            HtmlSanitizer::clean('<a href="tel:+48123456789">x</a>'),
        );
        self::assertStringContainsString(
            'href="/regulaminy/a.pdf"',
            HtmlSanitizer::clean('<a href="/regulaminy/a.pdf">x</a>'),
        );
    }

    public function testTargetBlankGetsNoopenerNoreferrer(): void
    {
        $out = HtmlSanitizer::clean('<a href="https://x.pl" target="_blank">x</a>');
        self::assertStringContainsString('target="_blank"', $out);
        self::assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $out);
        self::assertMatchesRegularExpression('/rel="[^"]*noreferrer[^"]*"/', $out);

        $self = HtmlSanitizer::clean('<a href="https://x.pl" target="_self">x</a>');
        self::assertStringNotContainsString('target=', $self);

        $foo = HtmlSanitizer::clean('<a href="https://x.pl" target="foo">x</a>');
        self::assertStringNotContainsString('target=', $foo);
    }

    public function testDropsStyleClassIdAttributes(): void
    {
        $out = HtmlSanitizer::clean('<p style="color:red" class="fixed inset-0" id="x">a</p>');
        self::assertStringNotContainsString('style=', $out);
        self::assertStringNotContainsString('class=', $out);
        self::assertStringNotContainsString('id=', $out);
    }

    public function testPreservesTipTapOutput(): void
    {
        $fixture = '<h2>Tytuł</h2><p><strong>B</strong> <em>I</em> <u>U</u> <s>S</s><br>'
            . '<a target="_blank" rel="noopener noreferrer nofollow" href="https://example.pl">link</a></p>'
            . '<ul><li><p>a</p></li></ul><ol start="3"><li><p>b</p></li></ol>'
            . '<blockquote><p>q</p></blockquote><pre><code>c</code></pre><hr>';

        $out = HtmlSanitizer::clean($fixture);

        self::assertStringContainsString('<h2>', $out);
        self::assertStringContainsString('<strong>', $out);
        self::assertStringContainsString('<em>', $out);
        self::assertStringContainsString('<u>', $out);
        self::assertStringContainsString('<s>', $out);
        self::assertStringContainsString('<br', $out);
        self::assertStringContainsString('<ul>', $out);
        self::assertStringContainsString('<ol start="3">', $out);
        self::assertStringContainsString('<blockquote>', $out);
        self::assertStringContainsString('<pre>', $out);
        self::assertStringContainsString('<hr', $out);
        self::assertStringContainsString('href="https://example.pl"', $out);
        self::assertStringContainsString('target="_blank"', $out);
    }

    /** @return array<string, array<int, string>> */
    public static function existingFixtureProvider(): array
    {
        return [
            'bold description' => ['<p><strong>Opis dla uczestnika</strong></p>'],
            'edited with emphasis' => ['<p>Opis po edycji z <em>formatowaniem</em></p>'],
            'list' => ['<ul><li>Regulamin testowy</li></ul>'],
            'link' => ['<p>Organizator <a href="https://example.test">Test</a></p>'],
        ];
    }

    #[DataProvider('existingFixtureProvider')]
    public function testExistingRepositoryFixturesUnchanged(string $input): void
    {
        self::assertSame($input, HtmlSanitizer::clean($input));
    }

    public function testIsIdempotent(): void
    {
        $fixture = '<h2>Tytuł</h2><p><strong>B</strong> '
            . '<a target="_blank" rel="noopener noreferrer nofollow" href="https://example.pl">link</a></p>';
        $once = HtmlSanitizer::clean($fixture);
        $twice = HtmlSanitizer::clean($once);
        self::assertSame($once, $twice);
    }

    public function testEmptyString(): void
    {
        self::assertSame('', HtmlSanitizer::clean(''));
    }

    public function testPolishCharactersPreserved(): void
    {
        self::assertSame('<p>Zażółć gęślą jaźń</p>', HtmlSanitizer::clean('<p>Zażółć gęślą jaźń</p>'));
    }

    public function testCleanNullablePassesNullThrough(): void
    {
        self::assertNull(HtmlSanitizer::cleanNullable(null));
        self::assertSame(42, HtmlSanitizer::cleanNullable(42));
    }

    public function testAllowlistParity(): void
    {
        self::assertSame(
            'p,br,strong,b,em,i,u,s,a[href|target|rel],h2,h3,ul,ol[start],li,blockquote,code,pre,hr',
            HtmlSanitizer::ALLOWED,
        );
    }
}
