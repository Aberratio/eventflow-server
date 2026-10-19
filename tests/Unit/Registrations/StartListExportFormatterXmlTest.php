<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Registrations;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Registrations\StartListExportFormatter;

/** G-11: XML start-list export — same columns/order as the CSV, valid UTF-8 XML, correct escaping. */
final class StartListExportFormatterXmlTest extends TestCase
{
    public function testXmlHasOneEntryPerRowWithEveryColumnInCsvOrder(): void
    {
        $columns = [
            ['key' => 'bib', 'label' => 'Numer startowy'],
            ['key' => 'name', 'label' => 'Imię i nazwisko'],
            ['key' => 'paid', 'label' => 'Status płatności'],
        ];
        $rows = [
            ['bib' => 7, 'name' => 'Zażółć Gęślą', 'paid' => 'Opłacona'],
            ['bib' => null, 'name' => 'Jan', 'paid' => 'Oczekująca'],
        ];

        $xml = StartListExportFormatter::toXml($columns, $rows);

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $doc = new \SimpleXMLElement($xml);
        self::assertCount(2, $doc->entry);
        self::assertCount(3, $doc->entry[0]->field);
        self::assertSame(['bib', 'name', 'paid'], array_map(static fn ($f): string => (string) $f['key'], iterator_to_array($doc->entry[0]->field, false)));
        self::assertSame('Status płatności', (string) $doc->entry[0]->field[2]['label']);
        self::assertSame('Zażółć Gęślą', (string) $doc->entry[0]->field[1]);
        self::assertSame('7', (string) $doc->entry[0]->field[0]);
        self::assertSame('', (string) $doc->entry[1]->field[0]);
    }

    public function testXmlEscapesMarkupQuotesAndDropsIllegalControlCharacters(): void
    {
        $columns = [['key' => 'custom_1', 'label' => 'Klub "A&B" <x>']];
        $rows = [['custom_1' => "Tom & <Jerry> 'x' \"y\"\x01\x0B ok\ttab"]];

        $xml = StartListExportFormatter::toXml($columns, $rows);

        $doc = new \SimpleXMLElement($xml); // throws on malformed XML
        self::assertSame("Tom & <Jerry> 'x' \"y\" ok\ttab", (string) $doc->entry[0]->field[0]);
        self::assertSame('Klub "A&B" <x>', (string) $doc->entry[0]->field[0]['label']);
        self::assertStringNotContainsString('<Jerry>', $xml);
    }

    public function testXmlWithoutRowsIsStillAWellFormedDocument(): void
    {
        $doc = new \SimpleXMLElement(StartListExportFormatter::toXml([['key' => 'a', 'label' => 'A']], []));

        self::assertSame('startList', $doc->getName());
        self::assertCount(0, $doc->entry);
    }
}
