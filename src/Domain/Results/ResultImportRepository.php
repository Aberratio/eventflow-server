<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Results;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;
use ZmierzymyCzas\Domain\Repository;

final class ResultImportRepository extends Repository
{
    private const BATCH_COLUMNS = <<<'SQL'
id, event_id AS eventId, distance_id AS distanceId, mode, status, file_name AS fileName,
file_path AS filePath, file_url AS fileUrl, file_mime_type AS fileMimeType, sheet_name AS sheetName,
headers, mapping, summary, uploaded_by AS uploadedBy, uploaded_at AS uploadedAt, committed_at AS committedAt
SQL;

    private const ROW_COLUMNS = <<<'SQL'
id, batch_id AS batchId, row_number AS rowNumber, raw_data AS rawData, mapped_data AS mappedData,
candidates, selected_registration_id AS selectedRegistrationId, selected_user_id AS selectedUserId,
status, errors, warnings, ignore_row AS ignoreRow, updated_at AS updatedAt
SQL;

    /** @param array{path: string, absolutePath: string, url: string, mimeType: string, originalName: string} $file */
    public function create(string $eventId, string $distanceId, string $mode, array $file, string $actorId): array
    {
        if (!in_array($mode, ['replace', 'upsert'], true)) {
            throw new RuntimeException('validation_error');
        }

        $parsed = $this->parseFile((string) $file['absolutePath'], (string) $file['mimeType']);
        $batchId = $this->id();
        $this->pdo->beginTransaction();
        try {
            $this->insert('result_import_batches', [
                'id' => $batchId,
                'event_id' => $eventId,
                'distance_id' => $distanceId,
                'mode' => $mode,
                'status' => 'uploaded',
                'file_name' => $file['originalName'],
                'file_path' => $file['absolutePath'],
                'file_url' => $file['url'],
                'file_mime_type' => $file['mimeType'],
                'sheet_name' => $parsed['sheetName'],
                'headers' => json_encode($parsed['headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mapping' => json_encode($this->suggestMapping($parsed['headers']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'summary' => json_encode([
                    'total' => count($parsed['rows']),
                    'ready' => 0,
                    'needsMatch' => 0,
                    'ambiguous' => 0,
                    'invalid' => count($parsed['rows']),
                    'ignored' => 0,
                    'sheets' => $parsed['sheets'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'uploaded_by' => $actorId,
                'uploaded_at' => date(DATE_ATOM),
            ]);

            foreach ($parsed['rows'] as $index => $row) {
                $this->insert('result_import_rows', [
                    'id' => $this->id(),
                    'batch_id' => $batchId,
                    'row_number' => $index + 2,
                    'raw_data' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'mapped_data' => null,
                    'candidates' => null,
                    'selected_registration_id' => null,
                    'selected_user_id' => null,
                    'status' => 'invalid',
                    'errors' => json_encode(['missing_mapping'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'warnings' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ignore_row' => 0,
                    'updated_at' => date(DATE_ATOM),
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->analyze($batchId, $this->suggestMapping($parsed['headers']), $parsed['sheetName']);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::BATCH_COLUMNS . ' FROM result_import_batches WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $batch = $this->hydrateBatch($stmt->fetch());
        if (!$batch) {
            return null;
        }
        $batch['rows'] = $this->rows($id);
        return $batch;
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(string $batchId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::ROW_COLUMNS . ' FROM result_import_rows WHERE batch_id = :batchId ORDER BY row_number ASC');
        $stmt->execute(['batchId' => $batchId]);
        return array_map(fn (array $row): array => $this->hydrateRow($row), $stmt->fetchAll());
    }

    /** @param array<string, string> $mapping */
    public function analyze(string $batchId, array $mapping, ?string $sheetName = null): array
    {
        $batch = $this->findBatchOnly($batchId);
        if (!$batch) {
            throw new RuntimeException('not_found');
        }

        if ($sheetName !== null && $sheetName !== '' && $sheetName !== ($batch['sheetName'] ?? null)) {
            $parsed = $this->parseFileFromBatch($batch, $sheetName);
            $this->replaceRows($batchId, $parsed['rows']);
            $this->updateById('result_import_batches', $batchId, [
                'headers' => json_encode($parsed['headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sheet_name' => $parsed['sheetName'],
            ]);
            $batch['headers'] = $parsed['headers'];
            $batch['sheetName'] = $parsed['sheetName'];
        }

        $registrations = $this->registrations((string) $batch['eventId'], (string) $batch['distanceId']);
        foreach ($this->rows($batchId) as $row) {
            if ((bool) $row['ignoreRow']) {
                continue;
            }
            $resolved = $this->resolveRow($row['rawData'], $mapping, $registrations, $row);
            $this->updateImportRow((string) $row['id'], $resolved);
        }

        $summary = $this->summary($batchId);
        $this->updateById('result_import_batches', $batchId, [
            'mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'analyzed',
        ]);

        return $this->find($batchId) ?? [];
    }

    /** @param array<string, mixed> $data */
    public function updateRow(string $rowId, array $data): array
    {
        $row = $this->row($rowId);
        if (!$row) {
            throw new RuntimeException('not_found');
        }
        $batch = $this->findBatchOnly((string) $row['batchId']);
        if (!$batch) {
            throw new RuntimeException('not_found');
        }

        $mapping = is_array($batch['mapping']) ? $batch['mapping'] : [];
        $registrations = $this->registrations((string) $batch['eventId'], (string) $batch['distanceId']);
        $mapped = is_array($row['mappedData']) ? $row['mappedData'] : $this->mapRaw($row['rawData'], $mapping);
        foreach (['bibNumber', 'time', 'status', 'position', 'category', 'gender', 'birthYear', 'firstName', 'lastName', 'fullName', 'club'] as $field) {
            if (array_key_exists($field, $data)) {
                $mapped[$field] = $data[$field];
            }
        }

        $ignore = (bool) ($data['ignoreRow'] ?? $row['ignoreRow']);
        $selectedRegistrationId = (string) ($data['selectedRegistrationId'] ?? $row['selectedRegistrationId'] ?? '');
        $selected = $selectedRegistrationId !== '' ? $this->findRegistration($registrations, $selectedRegistrationId) : null;
        $resolved = $ignore
            ? [
                'mappedData' => $mapped,
                'candidates' => [],
                'selectedRegistrationId' => null,
                'selectedUserId' => null,
                'status' => 'ignored',
                'errors' => [],
                'warnings' => [],
                'ignoreRow' => true,
            ]
            : $this->resolveMapped($mapped, $registrations, $selected);
        $this->updateImportRow($rowId, $resolved);

        $summary = $this->summary((string) $row['batchId']);
        $this->updateById('result_import_batches', (string) $row['batchId'], [
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->find((string) $row['batchId']) ?? [];
    }

    public function commit(string $batchId): array
    {
        $batch = $this->findBatchOnly($batchId);
        if (!$batch) {
            throw new RuntimeException('not_found');
        }

        $rows = array_values(array_filter($this->rows($batchId), static fn (array $row): bool => !((bool) $row['ignoreRow'])));
        $blocking = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] !== 'ready'));
        if ($blocking !== []) {
            throw new RuntimeException('result_import_unresolved_rows');
        }

        $event = $this->event((string) $batch['eventId']);
        $distance = $this->distance((string) $batch['distanceId']);
        if (!$event || !$distance) {
            throw new RuntimeException('validation_error');
        }

        $this->pdo->beginTransaction();
        try {
            if ($batch['mode'] === 'replace') {
                $delete = $this->pdo->prepare('DELETE FROM results WHERE event_id = :eventId AND distance_id = :distanceId');
                $delete->execute(['eventId' => $batch['eventId'], 'distanceId' => $batch['distanceId']]);
            }

            $positions = $this->positions($rows);
            foreach ($positions as $rowId => $positionData) {
                $row = $positionData['row'];
                $mapped = $row['mappedData'];
                $registration = $this->registrationById((string) $row['selectedRegistrationId']);
                if (!$registration) {
                    throw new RuntimeException('result_import_unresolved_rows');
                }

                if ($batch['mode'] === 'upsert') {
                    $this->pdo->prepare('DELETE FROM results WHERE event_id = :eventId AND distance_id = :distanceId AND user_id = :userId')->execute([
                        'eventId' => $batch['eventId'],
                        'distanceId' => $batch['distanceId'],
                        'userId' => $registration['userId'],
                    ]);
                }

                $this->insert('results', [
                    'id' => $this->id(),
                    'event_id' => $batch['eventId'],
                    'event_name' => $event['name'],
                    'distance_id' => $batch['distanceId'],
                    'distance_name' => $distance['name'],
                    'user_id' => $registration['userId'],
                    'registration_id' => $registration['id'],
                    'user_name' => $registration['userName'],
                    'bib_number' => $mapped['bibNumber'] !== '' ? $mapped['bibNumber'] : ($registration['bibNumber'] ?? ''),
                    'category' => $mapped['category'] !== '' ? $mapped['category'] : $registration['category'],
                    'time' => $mapped['status'] === 'finished' ? $mapped['time'] : strtoupper((string) $mapped['status']),
                    'time_in_seconds' => $mapped['status'] === 'finished' ? (int) $mapped['timeInSeconds'] : 0,
                    'position' => $mapped['status'] === 'finished' ? (int) $positionData['position'] : 0,
                    'category_position' => $mapped['status'] === 'finished' ? (int) $positionData['categoryPosition'] : 0,
                    'status' => $mapped['status'],
                    'gender' => $mapped['gender'] !== '' ? $mapped['gender'] : ($registration['gender'] ?? null),
                    'birth_year' => $mapped['birthYear'] !== '' ? (int) $mapped['birthYear'] : ($registration['birthYear'] ?? null),
                    'source_import_batch_id' => $batchId,
                    'source_import_row_id' => $rowId,
                ]);

                $this->updateById('result_import_rows', $rowId, ['status' => 'committed', 'updated_at' => date(DATE_ATOM)]);
            }

            $this->updateById('result_import_batches', $batchId, [
                'status' => 'committed',
                'committed_at' => date(DATE_ATOM),
                'summary' => json_encode($this->summary($batchId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($batchId) ?? [];
    }

    public function batchForRow(string $rowId): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT b.id, b.event_id AS eventId, b.distance_id AS distanceId, b.mode, b.status,
b.file_name AS fileName, b.file_path AS filePath, b.file_url AS fileUrl,
b.file_mime_type AS fileMimeType, b.sheet_name AS sheetName, b.headers, b.mapping,
b.summary, b.uploaded_by AS uploadedBy, b.uploaded_at AS uploadedAt, b.committed_at AS committedAt
FROM result_import_batches b
INNER JOIN result_import_rows r ON r.batch_id = b.id
WHERE r.id = :id
SQL);
        $stmt->execute(['id' => $rowId]);
        return $this->hydrateBatch($stmt->fetch());
    }

    /** @return array{headers: array<int, string>, rows: array<int, array<string, string>>, sheetName: ?string, sheets: array<int, string>} */
    private function parseFile(string $path, string $mimeType, ?string $sheetName = null): array
    {
        if (str_ends_with(strtolower($path), '.xlsx') || str_contains($mimeType, 'spreadsheetml')) {
            return $this->parseXlsx($path, $sheetName);
        }
        if (str_ends_with(strtolower($path), '.pdf') || $mimeType === 'application/pdf') {
            return $this->parsePdf($path);
        }
        return $this->parseCsv($path);
    }

    /** @return array{headers: array<int, string>, rows: array<int, array<string, string>>, sheetName: ?string, sheets: array<int, string>} */
    private function parseCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $first = $lines[0] ?? '';
        $delimiter = $this->detectDelimiter($first);
        $headers = [];
        foreach (str_getcsv($first, $delimiter) as $header) {
            $headers[] = $this->uniqueHeader((string) $header, $headers);
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        return ['headers' => $headers, 'rows' => $rows, 'sheetName' => null, 'sheets' => []];
    }

    /** @return array{headers: array<int, string>, rows: array<int, array<string, string>>, sheetName: ?string, sheets: array<int, string>} */
    private function parsePdf(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (!str_starts_with($content, '%PDF')) {
            throw new RuntimeException('unsupported_file_type');
        }

        $text = $this->extractPdfText($content);
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim(preg_replace('/\s+/', ' ', $line) ?? $line), preg_split('/\r\n|\n|\r/', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
        $rows = [];
        foreach ($lines as $line) {
            $row = $this->parsePdfResultLine($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            throw new RuntimeException(trim($text) === '' ? 'pdf_text_not_detected' : 'pdf_table_not_detected');
        }

        return [
            'headers' => ['Miejsce', 'Numer startowy', 'Imie i nazwisko', 'Czas', 'Status', 'Kategoria'],
            'rows' => $rows,
            'sheetName' => 'PDF eksperymentalnie',
            'sheets' => [],
        ];
    }

    /** @return array{headers: array<int, string>, rows: array<int, array<string, string>>, sheetName: ?string, sheets: array<int, string>} */
    private function parseXlsx(string $path, ?string $wantedSheetName = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('xlsx_not_supported');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('unsupported_file_type');
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sheets = $this->xlsxSheets($zip);
        $sheet = $sheets[0] ?? null;
        if ($wantedSheetName) {
            foreach ($sheets as $candidate) {
                if ($candidate['name'] === $wantedSheetName) {
                    $sheet = $candidate;
                    break;
                }
            }
        }
        if (!$sheet) {
            throw new RuntimeException('empty_import_file');
        }

        $xml = $zip->getFromName($sheet['path']);
        if ($xml === false) {
            throw new RuntimeException('empty_import_file');
        }
        $doc = new SimpleXMLElement($xml);
        $grid = [];
        foreach ($doc->sheetData->row as $row) {
            $rowIndex = (int) $row['r'];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $col = $this->xlsxColumnIndex($ref);
                $value = '';
                if (isset($cell->v)) {
                    $value = (string) $cell->v;
                    if ((string) $cell['t'] === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    }
                } elseif (isset($cell->is->t)) {
                    $value = (string) $cell->is->t;
                }
                $grid[$rowIndex][$col] = trim($value);
            }
        }
        $zip->close();

        if ($grid === []) {
            throw new RuntimeException('empty_import_file');
        }
        ksort($grid);
        $headerRow = array_shift($grid) ?: [];
        ksort($headerRow);
        $headers = [];
        foreach ($headerRow as $header) {
            $headers[] = $this->uniqueHeader($header === '' ? 'Kolumna ' . (count($headers) + 1) : $header, $headers);
        }
        $rows = [];
        foreach ($grid as $values) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index + 1] ?? ''));
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'sheetName' => $sheet['name'],
            'sheets' => array_column($sheets, 'name'),
        ];
    }

    private function extractPdfText(string $content): string
    {
        preg_match_all('/<<(.*?)>>\s*stream\r?\n?(.*?)\r?\n?endstream/s', $content, $matches, PREG_SET_ORDER);
        $text = '';
        foreach ($matches as $match) {
            $dictionary = $match[1] ?? '';
            $stream = $match[2] ?? '';
            if (str_contains($dictionary, 'FlateDecode')) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @zlib_decode($stream);
                }
                if ($decoded === false) {
                    continue;
                }
                $stream = $decoded;
            }
            $text .= "\n" . $this->extractPdfTextFromStream($stream);
        }
        return trim($text);
    }

    private function extractPdfTextFromStream(string $stream): string
    {
        $stream = preg_replace('/\b(Td|TD|T\*|ET|BT)\b/', "\n$1\n", $stream) ?? $stream;
        preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj|\[(.*?)\]\s*TJ/s', $stream, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $lines = [];
        $current = '';
        $cursor = 0;
        foreach ($matches as $match) {
            $before = substr($stream, $cursor, $match[0][1] - $cursor);
            if (str_contains($before, "\n")) {
                if (trim($current) !== '') {
                    $lines[] = trim($current);
                }
                $current = '';
            }
            $token = $match[0][0];
            if (str_ends_with($token, 'TJ')) {
                preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $token, $parts);
                foreach ($parts[0] ?? [] as $part) {
                    $current .= $this->decodePdfString($part);
                }
            } else {
                preg_match('/\((?:\\\\.|[^\\\\)])*\)/s', $token, $part);
                if (!empty($part[0])) {
                    $current .= $this->decodePdfString($part[0]);
                }
            }
            $current .= ' ';
            $cursor = $match[0][1] + strlen($match[0][0]);
        }
        if (trim($current) !== '') {
            $lines[] = trim($current);
        }
        return implode("\n", $lines);
    }

    private function decodePdfString(string $value): string
    {
        $value = substr($value, 1, -1);
        $value = preg_replace_callback('/\\\\([nrtbf\\\\()])/', static function (array $match): string {
            return match ($match[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'b' => "\b",
                'f' => "\f",
                default => $match[1],
            };
        }, $value) ?? $value;
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn (array $match): string => chr(octdec($match[1])), $value) ?? $value;
        try {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-2, ISO-8859-1');
            return is_string($converted) ? $converted : $value;
        } catch (\ValueError) {
            return $value;
        }
    }

    /** @return array<string, string>|null */
    private function parsePdfResultLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || !preg_match('/\d{1,2}:\d{2}(?::\d{2})?|DNF|DSQ|DNS|N\/A/i', $line)) {
            return null;
        }

        $pattern = '/^\s*(?:(\d{1,5})\s+)?(?:(\d{1,6})\s+)?(.+?)\s+(\d{1,2}:\d{2}(?::\d{2})?|DNF|DSQ|DNS|N\/A)(?:\s+(.+))?$/iu';
        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        $firstNumber = trim((string) ($matches[1] ?? ''));
        $secondNumber = trim((string) ($matches[2] ?? ''));
        $statusOrTime = trim((string) ($matches[4] ?? ''));
        $status = preg_match('/^(DNF|DSQ|DNS|N\/A)$/i', $statusOrTime) ? strtoupper($statusOrTime) : '';

        return [
            'Miejsce' => $secondNumber !== '' ? $firstNumber : '',
            'Numer startowy' => $secondNumber !== '' ? $secondNumber : $firstNumber,
            'Imie i nazwisko' => trim((string) ($matches[3] ?? '')),
            'Czas' => $status === '' ? $statusOrTime : '',
            'Status' => $status,
            'Kategoria' => trim((string) ($matches[5] ?? '')),
        ];
    }

    /** @return array<int, string> */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $doc = new SimpleXMLElement($xml);
        $strings = [];
        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }
        return $strings;
    }

    /** @return array<int, array{name: string, path: string}> */
    private function xlsxSheets(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return [];
        }
        $relDoc = new SimpleXMLElement($rels);
        $targets = [];
        foreach ($relDoc->Relationship as $rel) {
            $target = (string) $rel['Target'];
            if (str_starts_with($target, '/xl/')) {
                $targets[(string) $rel['Id']] = ltrim($target, '/');
            } elseif (str_starts_with($target, '/')) {
                $targets[(string) $rel['Id']] = ltrim($target, '/');
            } else {
                $targets[(string) $rel['Id']] = 'xl/' . ltrim($target, '/');
            }
        }
        $doc = new SimpleXMLElement($workbook);
        $doc->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = [];
        foreach ($doc->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('r', true);
            $relId = (string) $attrs['id'];
            if (isset($targets[$relId])) {
                $sheets[] = ['name' => (string) $sheet['name'], 'path' => $targets[$relId]];
            }
        }
        return $sheets;
    }

    private function xlsxColumnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/i', $ref, $matches);
        $letters = strtoupper($matches[1] ?? 'A');
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index;
    }

    /** @param array<int, string> $existing */
    private function uniqueHeader(string $header, array $existing = []): string
    {
        $base = trim($header) ?: 'Kolumna';
        $name = $base;
        $i = 2;
        while (in_array($name, $existing, true)) {
            $name = $base . ' ' . $i;
            $i++;
        }
        return $name;
    }

    private function detectDelimiter(string $line): string
    {
        $scores = [];
        foreach ([',', ';', "\t"] as $delimiter) {
            $scores[$delimiter] = count(str_getcsv($line, $delimiter));
        }
        arsort($scores);
        return (string) array_key_first($scores);
    }

    /** @param array<int, string> $headers @return array<string, string> */
    private function suggestMapping(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $key = $this->normalize($header);
            $target = null;
            if (str_contains($key, 'numer') || str_contains($key, 'bib')) {
                $target = 'bibNumber';
            } elseif (str_contains($key, 'imieinazwisko') || str_contains($key, 'zawodnik') || str_contains($key, 'uczestnik')) {
                $target = 'fullName';
            } elseif ($key === 'imie' || str_contains($key, 'firstname')) {
                $target = 'firstName';
            } elseif (str_contains($key, 'nazwisko') || str_contains($key, 'lastname')) {
                $target = 'lastName';
            } elseif (str_contains($key, 'czas') || str_contains($key, 'time')) {
                $target = 'time';
            } elseif (str_contains($key, 'status')) {
                $target = 'status';
            } elseif (str_contains($key, 'miejsce') || str_contains($key, 'pozycja') || str_contains($key, 'place')) {
                $target = 'position';
            } elseif (str_contains($key, 'kategoria')) {
                $target = 'category';
            } elseif (str_contains($key, 'plec') || str_contains($key, 'gender')) {
                $target = 'gender';
            } elseif (str_contains($key, 'rok')) {
                $target = 'birthYear';
            } elseif (str_contains($key, 'klub') || str_contains($key, 'club')) {
                $target = 'club';
            }
            if ($target && !isset($mapping[$target])) {
                $mapping[$target] = $header;
            }
        }
        return $mapping;
    }

    /** @param array<string, string> $raw @param array<string, string> $mapping @return array<string, mixed> */
    private function mapRaw(array $raw, array $mapping): array
    {
        $mapped = [
            'firstName' => '',
            'lastName' => '',
            'fullName' => '',
            'bibNumber' => '',
            'time' => '',
            'timeInSeconds' => null,
            'status' => '',
            'position' => '',
            'category' => '',
            'gender' => '',
            'birthYear' => '',
            'club' => '',
        ];
        foreach ($mapped as $field => $_) {
            $column = $mapping[$field] ?? null;
            if ($column !== null && array_key_exists($column, $raw)) {
                $mapped[$field] = trim((string) $raw[$column]);
            }
        }
        if ($mapped['fullName'] === '') {
            $mapped['fullName'] = trim($mapped['firstName'] . ' ' . $mapped['lastName']);
        }
        return $mapped;
    }

    /** @param array<string, string> $raw @param array<string, string> $mapping @param array<int, array<string, mixed>> $registrations @param array<string, mixed> $row */
    private function resolveRow(array $raw, array $mapping, array $registrations, array $row): array
    {
        $mapped = $this->mapRaw($raw, $mapping);
        $selected = !empty($row['selectedRegistrationId']) ? $this->findRegistration($registrations, (string) $row['selectedRegistrationId']) : null;
        return $this->resolveMapped($mapped, $registrations, $selected);
    }

    /** @param array<string, mixed> $mapped @param array<int, array<string, mixed>> $registrations */
    private function resolveMapped(array $mapped, array $registrations, ?array $selected): array
    {
        $errors = [];
        $warnings = [];
        $time = $this->parseTimeAndStatus((string) $mapped['time'], (string) $mapped['status']);
        $mapped['status'] = $time['status'];
        $mapped['time'] = $time['time'];
        $mapped['timeInSeconds'] = $time['seconds'];
        if ($time['error'] !== null) {
            $errors[] = $time['error'];
        }

        $match = $selected
            ? ['status' => 'ready', 'selected' => $selected, 'candidates' => [$selected]]
            : $this->matchRegistration($mapped, $registrations);
        $candidates = $match['candidates'] ?? [$selected];
        $candidates = array_values(array_filter($candidates));
        if (!$match['selected']) {
            $errors[] = $match['status'] === 'ambiguous' ? 'ambiguous_participant' : 'participant_not_matched';
        }
        if ((string) $mapped['category'] === '' && $match['selected']) {
            $mapped['category'] = (string) $match['selected']['category'];
        }
        if ((string) $mapped['bibNumber'] === '' && $match['selected']) {
            $mapped['bibNumber'] = (string) ($match['selected']['bibNumber'] ?? '');
        }

        $status = $errors === [] ? 'ready' : ($match['status'] === 'ambiguous' ? 'ambiguous' : ($match['status'] === 'needs_match' ? 'needs_match' : 'invalid'));
        return [
            'mappedData' => $mapped,
            'candidates' => $candidates,
            'selectedRegistrationId' => $match['selected']['id'] ?? null,
            'selectedUserId' => $match['selected']['userId'] ?? null,
            'status' => $status,
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'ignoreRow' => false,
        ];
    }

    /** @return array{time: string, seconds: ?int, status: string, error: ?string} */
    private function parseTimeAndStatus(string $rawTime, string $rawStatus): array
    {
        $status = strtolower(trim($rawStatus));
        $time = trim($rawTime);
        $normalizedStatus = match (true) {
            in_array($status, ['dnf', 'zszedl', 'zszedł', 'nie ukonczyl', 'nie ukończył'], true) => 'dnf',
            in_array($status, ['dsq', 'dq', 'dyskwalifikacja'], true) => 'dsq',
            in_array($status, ['finished', 'finish', 'ukonczyl', 'ukończył', ''], true) => 'finished',
            default => 'finished',
        };
        if (in_array(strtolower($time), ['dnf', 'dsq'], true)) {
            return ['time' => strtoupper($time), 'seconds' => 0, 'status' => strtolower($time), 'error' => null];
        }
        if ($normalizedStatus !== 'finished') {
            return ['time' => strtoupper($normalizedStatus), 'seconds' => 0, 'status' => $normalizedStatus, 'error' => null];
        }
        if ($time === '') {
            return ['time' => '', 'seconds' => null, 'status' => 'finished', 'error' => 'time_missing'];
        }
        if (!preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $time)) {
            return ['time' => $time, 'seconds' => null, 'status' => 'finished', 'error' => 'time_invalid'];
        }
        $parts = array_map('intval', explode(':', $time));
        if (count($parts) === 2) {
            [$m, $s] = $parts;
            $seconds = $m * 60 + $s;
            return ['time' => sprintf('%d:%02d', $m, $s), 'seconds' => $seconds, 'status' => 'finished', 'error' => null];
        }
        [$h, $m, $s] = $parts;
        $seconds = $h * 3600 + $m * 60 + $s;
        return ['time' => sprintf('%d:%02d:%02d', $h, $m, $s), 'seconds' => $seconds, 'status' => 'finished', 'error' => null];
    }

    /** @param array<string, mixed> $mapped @param array<int, array<string, mixed>> $registrations */
    private function matchRegistration(array $mapped, array $registrations): array
    {
        $bib = $this->normalize((string) $mapped['bibNumber']);
        if ($bib !== '') {
            $matches = array_values(array_filter($registrations, fn (array $r): bool => $this->normalize((string) ($r['bibNumber'] ?? '')) === $bib));
            if (count($matches) === 1) {
                return ['status' => 'ready', 'selected' => $matches[0], 'candidates' => $matches];
            }
            if (count($matches) > 1) {
                return ['status' => 'ambiguous', 'selected' => null, 'candidates' => $matches];
            }
        }

        $name = $this->normalize((string) $mapped['fullName']);
        $matches = array_values(array_filter($registrations, fn (array $r): bool => $this->normalize((string) $r['userName']) === $name));
        if (count($matches) === 1) {
            return ['status' => 'ready', 'selected' => $matches[0], 'candidates' => $matches];
        }
        if (count($matches) > 1) {
            $scored = [];
            foreach ($matches as $candidate) {
                $score = 0;
                if ($mapped['category'] !== '' && $this->normalize((string) $candidate['category']) === $this->normalize((string) $mapped['category'])) {
                    $score += 3;
                }
                if ($mapped['gender'] !== '' && strtoupper((string) $candidate['gender']) === strtoupper((string) $mapped['gender'])) {
                    $score += 2;
                }
                if ($mapped['birthYear'] !== '' && (int) $candidate['birthYear'] === (int) $mapped['birthYear']) {
                    $score += 2;
                }
                if ($mapped['club'] !== '' && $this->normalize((string) $candidate['club']) === $this->normalize((string) $mapped['club'])) {
                    $score += 1;
                }
                $candidate['_matchScore'] = $score;
                $scored[] = $candidate;
            }
            usort($scored, static fn (array $a, array $b): int => (int) $b['_matchScore'] <=> (int) $a['_matchScore']);
            if (($scored[0]['_matchScore'] ?? 0) > 0 && (($scored[1]['_matchScore'] ?? -1) < ($scored[0]['_matchScore'] ?? 0))) {
                return ['status' => 'ready', 'selected' => $scored[0], 'candidates' => $scored];
            }
            return ['status' => 'ambiguous', 'selected' => null, 'candidates' => $scored];
        }

        return ['status' => 'needs_match', 'selected' => null, 'candidates' => array_slice($registrations, 0, 20)];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $converted !== false ? $converted : $value;
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /** @return array<int, array<string, mixed>> */
    private function registrations(string $eventId, string $distanceId): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.user_id AS userId, r.user_name AS userName, r.user_email AS userEmail,
r.distance_id AS distanceId, r.distance_name AS distanceName, r.category, r.bib_number AS bibNumber,
r.status, u.gender, u.birth_year AS birthYear, u.club
FROM registrations r
LEFT JOIN users u ON u.id = r.user_id
WHERE r.event_id = :eventId AND r.distance_id = :distanceId AND r.status NOT IN ('removed','cancelled','expired')
ORDER BY r.user_name
SQL);
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
        return $stmt->fetchAll();
    }

    /** @param array<int, array<string, mixed>> $registrations */
    private function findRegistration(array $registrations, string $id): ?array
    {
        foreach ($registrations as $registration) {
            if ($registration['id'] === $id) {
                return $registration;
            }
        }
        return null;
    }

    private function registrationById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.user_id AS userId, r.user_name AS userName, r.user_email AS userEmail,
r.distance_id AS distanceId, r.distance_name AS distanceName, r.category, r.bib_number AS bibNumber,
u.gender, u.birth_year AS birthYear
FROM registrations r
LEFT JOIN users u ON u.id = r.user_id
WHERE r.id = :id
SQL);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    private function event(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    private function distance(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed> */
    private function summary(string $batchId): array
    {
        $summary = ['total' => 0, 'ready' => 0, 'needsMatch' => 0, 'ambiguous' => 0, 'invalid' => 0, 'ignored' => 0];
        foreach ($this->rows($batchId) as $row) {
            $summary['total']++;
            if ((bool) $row['ignoreRow'] || $row['status'] === 'ignored') {
                $summary['ignored']++;
            } elseif ($row['status'] === 'needs_match') {
                $summary['needsMatch']++;
            } elseif ($row['status'] === 'ambiguous') {
                $summary['ambiguous']++;
            } elseif ($row['status'] === 'ready') {
                $summary['ready']++;
            } elseif ($row['status'] === 'committed') {
                $summary['ready']++;
            } else {
                $summary['invalid']++;
            }
        }
        return $summary;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, array{row: array<string, mixed>, position: int, categoryPosition: int}> */
    private function positions(array $rows): array
    {
        $finished = array_values(array_filter($rows, static fn (array $row): bool => ($row['mappedData']['status'] ?? '') === 'finished'));
        usort($finished, static function (array $a, array $b): int {
            $aPos = (int) ($a['mappedData']['position'] ?? 0);
            $bPos = (int) ($b['mappedData']['position'] ?? 0);
            if ($aPos > 0 && $bPos > 0) {
                return $aPos <=> $bPos;
            }
            return (int) $a['mappedData']['timeInSeconds'] <=> (int) $b['mappedData']['timeInSeconds'];
        });
        $result = [];
        $categoryPositions = [];
        foreach ($finished as $index => $row) {
            $category = (string) ($row['mappedData']['category'] ?? '');
            $categoryPositions[$category] = ($categoryPositions[$category] ?? 0) + 1;
            $position = (int) ($row['mappedData']['position'] ?? 0);
            $result[(string) $row['id']] = [
                'row' => $row,
                'position' => $position > 0 ? $position : $index + 1,
                'categoryPosition' => $categoryPositions[$category],
            ];
        }
        foreach ($rows as $row) {
            if (($row['mappedData']['status'] ?? '') !== 'finished') {
                $result[(string) $row['id']] = ['row' => $row, 'position' => 0, 'categoryPosition' => 0];
            }
        }
        return $result;
    }

    /** @param array<string, mixed>|false|null $row */
    private function hydrateBatch(array|false|null $row): ?array
    {
        if (!$row) {
            return null;
        }
        foreach (['headers', 'mapping', 'summary'] as $field) {
            $row[$field] = $row[$field] ? json_decode((string) $row[$field], true) : null;
        }
        return $row;
    }

    /** @param array<string, mixed>|false|null $row */
    private function hydrateRow(array|false|null $row): ?array
    {
        if (!$row) {
            return null;
        }
        foreach (['rawData', 'mappedData', 'candidates', 'errors', 'warnings'] as $field) {
            $row[$field] = $row[$field] ? json_decode((string) $row[$field], true) : ($field === 'errors' || $field === 'warnings' ? [] : null);
        }
        $row['ignoreRow'] = (bool) $row['ignoreRow'];
        return $row;
    }

    private function findBatchOnly(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::BATCH_COLUMNS . ' FROM result_import_batches WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->hydrateBatch($stmt->fetch());
    }

    private function row(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::ROW_COLUMNS . ' FROM result_import_rows WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->hydrateRow($stmt->fetch());
    }

    /** @param array<string, mixed> $batch */
    private function parseFileFromBatch(array $batch, string $sheetName): array
    {
        $path = (string) $batch['filePath'];
        if (!is_file($path)) {
            $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        }
        return $this->parseFile($path, (string) $batch['fileMimeType'], $sheetName);
    }

    /** @param array<int, array<string, string>> $rows */
    private function replaceRows(string $batchId, array $rows): void
    {
        $this->pdo->prepare('DELETE FROM result_import_rows WHERE batch_id = :batchId')->execute(['batchId' => $batchId]);
        foreach ($rows as $index => $row) {
            $this->insert('result_import_rows', [
                'id' => $this->id(),
                'batch_id' => $batchId,
                'row_number' => $index + 2,
                'raw_data' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mapped_data' => null,
                'candidates' => null,
                'selected_registration_id' => null,
                'selected_user_id' => null,
                'status' => 'invalid',
                'errors' => json_encode(['missing_mapping'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'warnings' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ignore_row' => 0,
                'updated_at' => date(DATE_ATOM),
            ]);
        }
    }

    /** @param array<string, mixed> $resolved */
    private function updateImportRow(string $rowId, array $resolved): void
    {
        $this->updateById('result_import_rows', $rowId, [
            'mapped_data' => json_encode($resolved['mappedData'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'candidates' => json_encode($resolved['candidates'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'selected_registration_id' => $resolved['selectedRegistrationId'],
            'selected_user_id' => $resolved['selectedUserId'],
            'status' => $resolved['status'],
            'errors' => json_encode($resolved['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'warnings' => json_encode($resolved['warnings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ignore_row' => (int) $resolved['ignoreRow'],
            'updated_at' => date(DATE_ATOM),
        ]);
    }
}
