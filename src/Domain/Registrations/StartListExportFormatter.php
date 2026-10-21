<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use ZipArchive;

/**
 * Start list export ("Eksport listy startowej": CSV (Windows-1250) + XLSX).
 * Pure formatting — takes the {columns, rows} shape
 * RegistrationRepository::startListExportRows() already builds and turns it
 * into file bytes. Deliberately knows nothing about Zapisy, form fields, or
 * the live/frozen fork; that separation keeps this class testable with
 * plain arrays and reusable if a second export ever needs the same two
 * formats.
 *
 * No composer dependency added for either format (confirmed
 * PhpOffice/PhpSpreadsheet is absent from composer.json and stays absent):
 * CSV is built by hand with fputcsv() then transcoded, XLSX is a
 * hand-assembled minimal OOXML zip via ZipArchive — the same low-level tool
 * ResultImportRepository::parseXlsx() already uses to *read* .xlsx files,
 * used here in reverse to *write* one.
 */
final class StartListExportFormatter
{
    /**
     * CSV content, encoded Windows-1250 (a "realny wymóg polskiego
     * rynku" — the same reason ResultImportRepository/routes.php already
     * transliterate to ASCII elsewhere, except here the target charset
     * natively supports Polish diacritics, so nothing needs transliterating
     * in the normal case). `;` delimiter to match the one existing
     * client-side CSV export in this codebase (lib/exportUtils.ts) rather
     * than inventing a second delimiter convention.
     *
     * Built as UTF-8 first (fputcsv() handles quoting/escaping correctly
     * only when it isn't fighting a single-byte charset with different
     * "needs quoting" characters) and transcoded to Windows-1250 as the
     * very last step. `//TRANSLIT//IGNORE` combined (valid iconv syntax —
     * same combined-flags idiom already used for
     * ResultImportRepository::asciiFilenameSegment()'s
     * `ASCII//TRANSLIT//IGNORE`): TRANSLIT substitutes any character
     * Windows-1250 truly cannot represent with the closest approximation,
     * IGNORE drops anything TRANSLIT itself can't handle, so the export
     * never fatals on a stray character instead of losing the whole file.
     * The combined-flags syntax mirrors ResultImportRepository::normalize()'s
     * existing `ASCII//TRANSLIT//IGNORE` usage, just targeting Windows-1250
     * instead of ASCII.
     *
     * @param array<int, array{key: string, label: string}> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public static function toCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('csv_buffer_unavailable');
        }

        fputcsv($handle, array_map(static fn (array $c): string => $c['label'], $columns), ';');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (array $c): string => self::cellText($row[$c['key']] ?? ''),
                $columns
            ), ';');
        }

        rewind($handle);
        $utf8Csv = (string) stream_get_contents($handle);
        fclose($handle);

        $transcoded = iconv('UTF-8', 'Windows-1250//TRANSLIT//IGNORE', $utf8Csv);

        return $transcoded !== false ? $transcoded : $utf8Csv;
    }

    /**
     * Minimal but standards-compliant OOXML (ECMA-376) workbook: one sheet, one
     * header row, one row per registration. Uses inline strings
     * (`<c t="inlineStr"><is><t>…</t></is></c>`) for every text cell instead
     * of a shared-strings table — valid per the standard and simpler, since this
     * writer never needs to deduplicate strings across cells the way a
     * shared-strings table exists to optimize for. `[Content_Types].xml`,
     * `_rels/.rels`, `xl/workbook.xml` and `xl/_rels/workbook.xml.rels` are
     * the four parts every OOXML reader (Excel included) requires to open a
     * workbook at all; `xl/worksheets/sheet1.xml` is the one sheet's data.
     * No `xl/styles.xml` is included — a worksheet may omit it entirely and
     * fall back to the default (unstyled) cell format, so this stays
     * deliberately minimal rather than shipping a styles part nothing here
     * uses.
     *
     * Verified structurally in this environment (no real Excel available
     * here) by reopening the produced bytes with ZipArchive and re-parsing
     * every XML part — see StartListExportFormatterTest — but never
     * visually confirmed in Excel itself; flagged as a residual risk.
     *
     * @param array<int, array{key: string, label: string}> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public static function toXlsx(array $columns, array $rows): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('xlsx_not_supported');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'zc_xlsx_');
        if ($tmpPath === false) {
            throw new \RuntimeException('xlsx_tmp_unavailable');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpPath);
            throw new \RuntimeException('xlsx_zip_open_failed');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheetXml($columns, $rows));
        $zip->close();

        $bytes = (string) file_get_contents($tmpPath);
        @unlink($tmpPath);

        return $bytes;
    }

    /**
     * XML start list export. UTF-8 XML with the same columns, in the
     * same order, as the CSV: one `<entry>` per registration, one `<field key="…" label="…">` per
     * column (keys/labels go into attributes, never element names, so a custom form field with an
     * arbitrary label cannot produce an invalid document). Characters illegal in XML 1.0 are
     * dropped; everything else is escaped.
     *
     * @param array<int, array{key: string, label: string}> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public static function toXml(array $columns, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<startList>' . "\n";
        foreach ($rows as $row) {
            $xml .= "  <entry>\n";
            foreach ($columns as $column) {
                $xml .= '    <field key="' . self::xmlEscape($column['key']) . '" label="' . self::xmlEscape($column['label']) . '">'
                    . self::xmlEscape(self::cellText($row[$column['key']] ?? '')) . "</field>\n";
            }
            $xml .= "  </entry>\n";
        }

        return $xml . '</startList>' . "\n";
    }

    private static function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $v): string => (string) $v, $value));
        }
        return (string) $value;
    }

    private static function xmlEscape(string $value): string
    {
        // Control characters other than TAB/LF/CR are not allowed in XML 1.0 at all (even escaped).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;
    }

    private static function rootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private static function workbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Lista startowa" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private static function workbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;
    }

    /**
     * @param array<int, array{key: string, label: string}> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private static function sheetXml(array $columns, array $rows): string
    {
        $allRows = [array_map(static fn (array $c): string => $c['label'], $columns)];
        foreach ($rows as $row) {
            $allRows[] = array_map(static fn (array $c): string => self::cellText($row[$c['key']] ?? ''), $columns);
        }

        $xmlRows = [];
        foreach ($allRows as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            $xmlCells = [];
            foreach (array_values($cells) as $colIndex => $cellValue) {
                $ref = self::columnLetter($colIndex + 1) . $rowNumber;
                $xmlCells[] = '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xmlEscape($cellValue) . '</t></is></c>';
            }
            $xmlRows[] = '<row r="' . $rowNumber . '">' . implode('', $xmlCells) . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
            . '</worksheet>';
    }

    private static function columnLetter(int $oneBasedIndex): string
    {
        $letters = '';
        while ($oneBasedIndex > 0) {
            $remainder = ($oneBasedIndex - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $oneBasedIndex = intdiv($oneBasedIndex - 1, 26);
        }
        return $letters;
    }
}
