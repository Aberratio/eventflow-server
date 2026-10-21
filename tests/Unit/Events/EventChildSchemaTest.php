<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Events\EventChildSchema;
use ZmierzymyCzas\Support\InputValidationException;

/**
 * The only place where client keys of event child rows are turned into column names.
 * Column names come from constants, never from input.
 */
final class EventChildSchemaTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function hostileKeyProvider(): array
    {
        return [
            'backtick injection' => ['name`) SELECT 1 -- '],
            'backtick in the middle' => ['a`b'],
            'event_id' => ['event_id'],
            'eventId' => ['eventId'],
            'unknown column' => ['passwordHash'],
            'snake_case spelling of a real key' => ['route_gpx_url'],
            'system column spelled snake' => ['registered_count'],
            'empty key' => [''],
        ];
    }

    #[DataProvider('hostileKeyProvider')]
    public function testUnknownAndInjectionKeysAreNeverColumns(string $key): void
    {
        foreach (array_keys(EventChildSchema::COLLECTIONS) as $collection) {
            $item = $this->minimal($collection) + [$key => 'HOSTILE'];
            $out = EventChildSchema::normalize($collection, $item, $collection . '[0]', false);

            if ($key !== 'eventId') {
                self::assertContains($key, $out['unknownKeys'], $collection);
            }
            self::assertNotContains('HOSTILE', array_values($out['columns']), $collection);
            self::assertSame([], array_diff(array_keys($out['columns']), EventChildSchema::allColumns($collection)), $collection);
            foreach (array_keys($out['columns']) as $column) {
                self::assertMatchesRegularExpression('/^[a-z_]+$/', $column);
            }
        }
    }

    public function testReadOnlyKeysAreIgnoredWithoutLogging(): void
    {
        $item = $this->minimal('distances') + [
            'paidCount' => 3, 'slotsRemaining' => 9, 'registeredCount' => 77, 'sortOrder' => 4, 'id' => 'abc', 'eventId' => 'e', 'participantLimit' => 5,
            'pricingVariants' => [],
        ];
        $out = EventChildSchema::normalize('distances', $item, 'distances[0]', false);
        self::assertSame([], $out['unknownKeys']);
        self::assertArrayNotHasKey('registered_count', $out['columns']);
        self::assertArrayNotHasKey('sort_order', $out['columns']);
        self::assertArrayNotHasKey('id', $out['columns']);
        self::assertNull($out['columns']['participant_limit']);

        $code = EventChildSchema::normalize('discountCodes', [
            'code' => 'X', 'discountType' => 'percent', 'discountValue' => 10, 'usedCount' => 0, 'usedBy' => [],
        ], 'discountCodes[0]', false);
        self::assertSame([], $code['unknownKeys']);
        self::assertArrayNotHasKey('used_count', $code['columns']);
        self::assertArrayNotHasKey('used_by', $code['columns']);
    }

    public function testClientColumnsExcludeSystemColumns(): void
    {
        self::assertNotContains('registered_count', EventChildSchema::clientColumns('distances'));
        self::assertNotContains('used_count', EventChildSchema::clientColumns('discountCodes'));
        self::assertNotContains('used_by', EventChildSchema::clientColumns('discountCodes'));
        self::assertNotContains('shop_product_id', EventChildSchema::clientColumns('merchandise'));
        self::assertContains('registered_count', EventChildSchema::allColumns('distances'));
        self::assertContains('used_by', EventChildSchema::allColumns('discountCodes'));
    }

    public function testTrustedModeAllowsSystemColumns(): void
    {
        $d = EventChildSchema::normalize('distances', $this->minimal('distances') + ['registeredCount' => 4], 'distances[0]', true);
        self::assertSame(4, $d['columns']['registered_count']);
        self::assertSame([], $d['unknownKeys']);

        $d = EventChildSchema::normalize('distances', $this->minimal('distances'), 'distances[0]', true);
        self::assertSame(0, $d['columns']['registered_count']);

        $c = EventChildSchema::normalize('discountCodes', [
            'code' => 'X', 'discountType' => 'fixed', 'discountValue' => 5, 'usedCount' => 2, 'usedBy' => [['userId' => 'u']],
        ], 'discountCodes[0]', true);
        self::assertSame(2, $c['columns']['used_count']);
        self::assertSame([['userId' => 'u']], json_decode((string) $c['columns']['used_by'], true));
    }

    public function testFullReplaceDefaults(): void
    {
        $out = EventChildSchema::normalize('distances', ['name' => '5 km', 'unit' => 'km'], 'distances[0]', false)['columns'];
        self::assertSame('individual', $out['start_type']);
        self::assertSame('per_person', $out['team_pricing_mode']);
        self::assertEquals(0, $out['price']);
        self::assertNull($out['length']);
        self::assertNull($out['participant_limit']);
        self::assertNull($out['categories']);
        self::assertNull($out['team_aggregation_rule']);
    }

    public function testLimitMapsToParticipantLimit(): void
    {
        $out = EventChildSchema::normalize('distances', ['name' => '5 km', 'unit' => 'km', 'limit' => 120], 'distances[0]', false)['columns'];
        self::assertSame(120, (int) $out['participant_limit']);
        self::assertArrayNotHasKey('limit', $out);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}> */
    public static function invalidProvider(): array
    {
        $longName = str_repeat('a', 181);
        $longUrl = 'https://e.test/' . str_repeat('a', 520);

        return [
            'missing name' => ['distances', ['unit' => 'km'], 'distances[0].name', 'Nazwa dystansu'],
            'blank name' => ['distances', ['name' => '  ', 'unit' => 'km'], 'distances[0].name', 'Nazwa dystansu'],
            'bad unit' => ['distances', ['name' => 'a', 'unit' => 'mile'], 'distances[0].unit', 'Jednostka długości'],
            'missing unit' => ['distances', ['name' => 'a'], 'distances[0].unit', 'Jednostka długości'],
            'name too long' => ['distances', ['name' => $longName, 'unit' => 'km'], 'distances[0].name', 'Nazwa dystansu'],
            'bad date' => ['distances', ['name' => 'a', 'unit' => 'km', 'relayTeamCompletionDeadline' => 'abc'], 'distances[0].relayTeamCompletionDeadline', 'Termin kompletowania drużyny'],
            'impossible date' => ['distances', ['name' => 'a', 'unit' => 'km', 'relayTeamCompletionDeadline' => '2026-02-31'], 'distances[0].relayTeamCompletionDeadline', 'Termin kompletowania drużyny'],
            'negative price' => ['distances', ['name' => 'a', 'unit' => 'km', 'price' => -1], 'distances[0].price', 'Cena dystansu'],
            'non numeric price' => ['distances', ['name' => 'a', 'unit' => 'km', 'price' => 'abc'], 'distances[0].price', 'Cena dystansu'],
            'price out of range' => ['distances', ['name' => 'a', 'unit' => 'km', 'price' => 1e12], 'distances[0].price', 'Cena dystansu'],
            'int overflow' => ['distances', ['name' => 'a', 'unit' => 'km', 'limit' => 2147483648], 'distances[0].limit', 'Limit uczestników'],
            'categories not json' => ['distances', ['name' => 'a', 'unit' => 'km', 'categories' => 'not-json'], 'distances[0].categories', 'Kategorie'],
            'bad start type' => ['distances', ['name' => 'a', 'unit' => 'km', 'startType' => 'x'], 'distances[0].startType', 'Rodzaj startu'],
            'bad enum aggregation' => ['distances', ['name' => 'a', 'unit' => 'km', 'teamAggregationRule' => 'x'], 'distances[0].teamAggregationRule', 'Reguła wyniku drużynowego'],
            'url too long' => ['distances', ['name' => 'a', 'unit' => 'km', 'routeGpxUrl' => $longUrl], 'distances[0].routeGpxUrl', 'Plik GPX'],
            'array as text' => ['distances', ['name' => ['a'], 'unit' => 'km'], 'distances[0].name', 'Nazwa dystansu'],
            'doc missing url' => ['documents', ['name' => 'R'], 'documents[0].fileUrl', 'Plik dokumentu'],
            'doc bad type' => ['documents', ['name' => 'R', 'fileUrl' => '/a.pdf', 'type' => 'other'], 'documents[0].type', 'Rodzaj dokumentu'],
            'doc missing name' => ['documents', ['fileUrl' => '/a.pdf'], 'documents[0].name', 'Nazwa dokumentu'],
            'doc no filename and no basename' => ['documents', ['name' => 'R', 'fileUrl' => '/'], 'documents[0].fileName', 'Nazwa pliku dokumentu'],
            'discount bad type' => ['discountCodes', ['code' => 'A', 'discountType' => 'x', 'discountValue' => 1], 'discountCodes[0].discountType', 'Rodzaj rabatu'],
            'discount missing type' => ['discountCodes', ['code' => 'A', 'discountValue' => 1], 'discountCodes[0].discountType', 'Rodzaj rabatu'],
            'discount missing value' => ['discountCodes', ['code' => 'A', 'discountType' => 'fixed'], 'discountCodes[0].discountValue', 'Wartość rabatu'],
            'discount missing code' => ['discountCodes', ['discountType' => 'fixed', 'discountValue' => 1], 'discountCodes[0].code', 'Kod rabatowy'],
            'discount distanceIds not list' => ['discountCodes', ['code' => 'A', 'discountType' => 'fixed', 'discountValue' => 1, 'distanceIds' => 'a'], 'discountCodes[0].distanceIds', 'Dystanse kodu'],
            'merch bad quantityMode' => ['merchandise', ['name' => 'K', 'quantityMode' => 'many'], 'merchandise[0].quantityMode', 'Tryb ilości'],
            'merch missing name' => ['merchandise', ['price' => 1], 'merchandise[0].name', 'Nazwa gadżetu'],
            'merch bad bool' => ['merchandise', ['name' => 'K', 'available' => 'maybe'], 'merchandise[0].available', 'Dostępny'],
            'album missing url' => ['galleryAlbums', ['name' => 'A', 'platform' => 'x'], 'galleryAlbums[0].url', 'Adres albumu'],
            'album missing platform' => ['galleryAlbums', ['name' => 'A', 'url' => 'https://a'], 'galleryAlbums[0].platform', 'Serwis albumu'],
            'payment method missing name' => ['paymentMethods', ['enabled' => true], 'paymentMethods[0].name', 'Nazwa metody płatności'],
            'variant missing price' => ['pricingVariants', ['name' => 'Biuro'], 'pricingVariants[0].price', 'Cena wariantu opłaty'],
            'variant missing name' => ['pricingVariants', ['price' => 1], 'pricingVariants[0].name', 'Nazwa wariantu opłaty'],
        ];
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('invalidProvider')]
    public function testValidationMessagesAndFields(string $collection, array $item, string $field, string $label): void
    {
        try {
            EventChildSchema::normalize($collection, $item, $collection . '[0]', false);
            self::fail('Expected InputValidationException for ' . $field);
        } catch (InputValidationException $e) {
            self::assertSame($field, $e->field);
            self::assertStringContainsString('„' . $label . '”', $e->getMessage());
        }
    }

    public function testLengthMessageIsPolish(): void
    {
        try {
            EventChildSchema::normalize('distances', ['name' => str_repeat('a', 181), 'unit' => 'km'], 'distances[0]', false);
            self::fail('expected exception');
        } catch (InputValidationException $e) {
            self::assertStringContainsString('najwyżej 180 znaków', $e->getMessage());
        }
    }

    public function testMultibyteLengthIsCountedInCharacters(): void
    {
        $out = EventChildSchema::normalize('distances', ['name' => str_repeat('ł', 180), 'unit' => 'km'], 'distances[0]', false);
        self::assertSame(180, mb_strlen((string) $out['columns']['name']));
    }

    public function testDocumentFileNameFallsBackToUrlBasename(): void
    {
        foreach ([[], ['fileName' => null], ['fileName' => '  ']] as $extra) {
            $out = EventChildSchema::normalize('documents', ['name' => 'R', 'fileUrl' => '/uploads/event-docs/a.pdf?x=1'] + $extra, 'documents[0]', false)['columns'];
            self::assertSame('a.pdf', $out['file_name']);
            self::assertSame('application/octet-stream', $out['mime_type']);
            self::assertSame('inne', $out['type']);
        }
        $out = EventChildSchema::normalize('documents', ['name' => 'R', 'fileUrl' => '/a.pdf', 'fileName' => 'Regulamin.pdf', 'mimeType' => 'application/pdf', 'type' => 'regulamin'], 'documents[0]', false)['columns'];
        self::assertSame('Regulamin.pdf', $out['file_name']);
        self::assertSame('application/pdf', $out['mime_type']);
    }

    public function testJsonStringIsDecodedOrRejected(): void
    {
        $ok = EventChildSchema::normalize('distances', ['name' => 'a', 'unit' => 'km', 'categories' => '["Open","M40"]'], 'distances[0]', false)['columns'];
        self::assertSame(['Open', 'M40'], json_decode((string) $ok['categories'], true));

        $arr = EventChildSchema::normalize('distances', ['name' => 'a', 'unit' => 'km', 'categories' => ['Open'], 'priceTiers' => [['price' => 1]]], 'distances[0]', false)['columns'];
        self::assertSame(['Open'], json_decode((string) $arr['categories'], true));
        self::assertSame([['price' => 1]], json_decode((string) $arr['price_tiers'], true));

        foreach (['{"a":1', '"just a string"', '12'] as $bad) {
            try {
                EventChildSchema::normalize('distances', ['name' => 'a', 'unit' => 'km', 'categories' => $bad], 'distances[0]', false);
                self::fail('expected exception for ' . $bad);
            } catch (InputValidationException $e) {
                self::assertSame('distances[0].categories', $e->field);
            }
        }
    }

    public function testBooleansAreStoredAsIntegers(): void
    {
        $out = EventChildSchema::normalize('paymentMethods', ['name' => 'Przelew', 'enabled' => false], 'paymentMethods[0]', false)['columns'];
        self::assertSame(0, $out['enabled']);
        $out = EventChildSchema::normalize('paymentMethods', ['name' => 'Przelew'], 'paymentMethods[0]', false)['columns'];
        self::assertSame(1, $out['enabled']);
        $out = EventChildSchema::normalize('pricingVariants', ['name' => 'Biuro', 'price' => 10, 'officeOnly' => true], 'pricingVariants[0]', false)['columns'];
        self::assertSame(1, $out['office_only']);
        self::assertSame(1, $out['visible_in_public_form']);
    }

    public function testHtmlIsSanitized(): void
    {
        $out = EventChildSchema::normalize('distances', ['name' => 'a', 'unit' => 'km', 'infoHtml' => '<p>ok</p><script>alert(1)</script>'], 'distances[0]', false)['columns'];
        self::assertStringNotContainsString('<script', (string) $out['info_html']);
        self::assertStringContainsString('<p>ok</p>', (string) $out['info_html']);

        $out = EventChildSchema::normalize('merchandise', ['name' => 'K', 'descriptionHtml' => '<img src=x onerror=alert(1)>'], 'merchandise[0]', false)['columns'];
        self::assertStringNotContainsString('onerror', (string) $out['description_html']);
    }

    public function testLengthIsCheckedAfterSanitizing(): void
    {
        $stripped = '<script>' . str_repeat('a', 70000) . '</script>';
        $out = EventChildSchema::normalize('distances', ['name' => 'a', 'unit' => 'km', 'infoHtml' => $stripped], 'distances[0]', false)['columns'];
        self::assertLessThan(65535, strlen((string) $out['info_html']));

        $this->expectException(InputValidationException::class);
        EventChildSchema::normalize('distances', ['name' => 'a', 'unit' => 'km', 'infoHtml' => '<p>' . str_repeat('a', 70000) . '</p>'], 'distances[0]', false);
    }

    public function testNumericStringsAreAccepted(): void
    {
        $out = EventChildSchema::normalize('distances', ['name' => 'a', 'unit' => 'm', 'price' => '49.90', 'length' => '10.5', 'limit' => '100'], 'distances[0]', false)['columns'];
        self::assertEquals(49.9, $out['price']);
        self::assertEquals(10.5, $out['length']);
        self::assertSame(100, (int) $out['participant_limit']);
    }

    /** @return array<string, mixed> */
    private function minimal(string $collection): array
    {
        return match ($collection) {
            'distances' => ['name' => 'a', 'unit' => 'km'],
            'paymentMethods' => ['name' => 'a'],
            'merchandise' => ['name' => 'a'],
            'discountCodes' => ['code' => 'a', 'discountType' => 'percent', 'discountValue' => 1],
            'galleryAlbums' => ['name' => 'a', 'url' => 'https://a.test', 'platform' => 'x'],
            'documents' => ['name' => 'a', 'fileUrl' => '/a.pdf'],
        };
    }
}
