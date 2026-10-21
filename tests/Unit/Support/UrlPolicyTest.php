<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Support\HtmlSanitizer;
use ZmierzymyCzas\Support\UrlPolicy;

/**
 * The corpus below is shared with client src/lib/__tests__/safeUrl.test.ts.
 * Expected values: [input, link result (null = rejected), frame result (null = rejected)].
 */
final class UrlPolicyTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> */
    public static function corpusProvider(): array
    {
        $rows = [
            ['https://example.com/x', 'https://example.com/x', 'https://example.com/x'],
            ['http://example.com', 'http://example.com', 'http://example.com'],
            ['HTTPS://EXAMPLE.COM', 'HTTPS://EXAMPLE.COM', 'HTTPS://EXAMPLE.COM'],
            ['  https://example.com  ', 'https://example.com', 'https://example.com'],
            ['www.example.pl', 'https://www.example.pl', 'https://www.example.pl'],
            ['example.pl/zapisy?x=1', 'https://example.pl/zapisy?x=1', 'https://example.pl/zapisy?x=1'],
            ['/uploads/event-docs/a.pdf', '/uploads/event-docs/a.pdf', null],
            ['#sekcja', '#sekcja', null],
            ['?q=1', '?q=1', null],
            ['//evil.com/x', null, null],
            ['/\\evil.com', null, null],
            ['mailto:biuro@example.pl', 'mailto:biuro@example.pl', null],
            ['tel:+48123456789', 'tel:+48123456789', null],
            ['javascript:alert(1)', null, null],
            ['JaVaScRiPt:alert(1)', null, null],
            [' javascript:alert(1)', null, null],
            ["java\tscript:alert(1)", null, null],
            ["java\nscript:alert(1)", null, null],
            ["\0javascript:alert(1)", null, null],
            ['javascript://example.com/%0Aalert(1)', null, null],
            ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', null, null],
            ['vbscript:msgbox(1)', null, null],
            ['https:evil.com', null, null],
            ['https://', null, null],
            ['ftp://example.com', null, null],
            ['brak', null, null],
            ['localhost:3000', null, null],
        ];
        $out = [];
        foreach ($rows as [$in, $link, $frame]) {
            $out[json_encode($in)] = [$in, $link, $frame];
        }
        return $out;
    }

    #[DataProvider('corpusProvider')]
    public function testNormalizeSharedCorpus(string $input, ?string $link, ?string $frame): void
    {
        foreach ([[UrlPolicy::LINK, $link], [UrlPolicy::FRAME, $frame]] as [$kind, $expected]) {
            $r = UrlPolicy::normalize($input, $kind);
            if ($expected === null) {
                self::assertFalse($r['ok'], "$kind must reject " . json_encode($input));
            } else {
                self::assertTrue($r['ok'], "$kind must accept " . json_encode($input));
                self::assertSame($expected, $r['value']);
            }
        }
    }

    public function testEmptyAndNullAreKept(): void
    {
        self::assertSame(['ok' => true, 'value' => ''], UrlPolicy::normalize('', UrlPolicy::LINK));
        self::assertSame(['ok' => true, 'value' => null], UrlPolicy::normalize(null, UrlPolicy::FRAME));
    }

    public function testNonStringsAreRejected(): void
    {
        foreach ([['javascript:x'], 42, true, 1.5] as $v) {
            self::assertFalse(UrlPolicy::normalize($v, UrlPolicy::LINK)['ok']);
        }
    }

    public function testApplyReportsConcretePathOfNestedListItem(): void
    {
        $r = UrlPolicy::apply(
            ['organizerInfo' => ['sponsors' => [['url' => 'https://ok.pl'], ['url' => 'javascript:x']]]],
            UrlPolicy::EVENT
        );
        self::assertSame('organizerInfo.sponsors[1].url', $r['error']['field'] ?? null);
        self::assertStringContainsString('Link sponsora', $r['error']['message']);
        self::assertStringContainsString('Pole „', $r['error']['message']);
    }

    public function testApplyWalksMapWildcard(): void
    {
        $r = UrlPolicy::apply(
            ['logisticsInfo' => ['parking' => ['locations' => [['mapsUrl' => 'data:text/html,x']]]]],
            UrlPolicy::EVENT
        );
        self::assertSame('logisticsInfo.parking.locations[0].mapsUrl', $r['error']['field'] ?? null);
        $ok = UrlPolicy::apply(
            ['logisticsInfo' => ['raceOffice' => ['locations' => [['mapsUrl' => 'https://maps.example']]]]],
            UrlPolicy::EVENT
        );
        self::assertNull($ok['error']);
    }

    public function testSnakeCaseKeyMatchesCamelRule(): void
    {
        $r = UrlPolicy::apply(['distances' => [['route_gpx_url' => 'javascript:x']]], UrlPolicy::EVENT);
        self::assertSame('distances[0].route_gpx_url', $r['error']['field'] ?? null);
    }

    public function testJsonStringContainerIsDecodedValidatedAndWrittenBack(): void
    {
        $bad = UrlPolicy::apply(
            ['registrationEmbed' => '{"mode":"external_embed","externalUrl":"javascript:x"}'],
            UrlPolicy::EVENT
        );
        self::assertSame('registrationEmbed.externalUrl', $bad['error']['field'] ?? null);

        $good = UrlPolicy::apply(
            ['registrationEmbed' => '{"mode":"external_embed","externalUrl":"www.example.pl/form"}'],
            UrlPolicy::EVENT
        );
        self::assertNull($good['error']);
        self::assertSame(
            ['mode' => 'external_embed', 'externalUrl' => 'https://www.example.pl/form'],
            $good['payload']['registrationEmbed']
        );
    }

    public function testNonStringLeafIsAViolation(): void
    {
        $r = UrlPolicy::apply(['liveResultsUrl' => ['javascript:x']], UrlPolicy::EVENT);
        self::assertSame('liveResultsUrl', $r['error']['field'] ?? null);
    }

    public function testAbsentKeysAndNonArrayContainersAreSkipped(): void
    {
        $r = UrlPolicy::apply(['title' => 'x', 'organizerInfo' => 'plain text', 'distances' => 5], UrlPolicy::EVENT);
        self::assertNull($r['error']);
    }

    public function testBareHostIsWrittenBackAsHttps(): void
    {
        $r = UrlPolicy::apply(['organizerContact' => ['website' => ' www.example.pl ']], UrlPolicy::EVENT);
        self::assertNull($r['error']);
        self::assertSame('https://www.example.pl', $r['payload']['organizerContact']['website']);
    }

    public function testLeafListPath(): void
    {
        $r = UrlPolicy::apply(['images' => ['https://a.pl/1.jpg', 'javascript:x']], UrlPolicy::SHOP_PRODUCT);
        self::assertSame('images[1]', $r['error']['field'] ?? null);
        $ok = UrlPolicy::apply(['images' => ['https://a.pl/1.jpg', 'b.pl/2.jpg']], UrlPolicy::SHOP_PRODUCT);
        self::assertNull($ok['error']);
        self::assertSame(['https://a.pl/1.jpg', 'https://b.pl/2.jpg'], $ok['payload']['images']);
    }

    public function testFirstViolationWins(): void
    {
        $r = UrlPolicy::apply(
            ['locationMapsUrl' => 'javascript:a', 'liveResultsUrl' => 'javascript:b'],
            UrlPolicy::EVENT
        );
        self::assertSame('locationMapsUrl', $r['error']['field'] ?? null);
    }

    public function testFrameMessageIsPolish(): void
    {
        $r = UrlPolicy::apply(['registrationEmbed' => ['externalUrl' => '/x']], UrlPolicy::EVENT);
        self::assertSame(
            'Pole „Adres zewnętrznego formularza zapisów” musi zawierać adres strony zaczynający się od http:// lub https://.',
            $r['error']['message'] ?? null
        );
    }

    public function testClearingFieldsIsAccepted(): void
    {
        $r = UrlPolicy::apply(['liveResultsUrl' => '', 'imageUrl' => null], UrlPolicy::EVENT);
        self::assertNull($r['error']);
    }

    public function testHtmlSanitizerUsesSharedSchemes(): void
    {
        self::assertSame(['http', 'https', 'mailto', 'tel'], UrlPolicy::LINK_SCHEMES);
        $out = HtmlSanitizer::clean('<a href="tel:1">x</a><a href="javascript:x">y</a>');
        self::assertStringContainsString('href="tel:1"', $out);
        self::assertStringNotContainsString('javascript', $out);
    }
}
