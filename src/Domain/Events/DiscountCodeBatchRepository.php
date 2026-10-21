<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Support\InputValidationException;

/**
 * Batch generator for discount codes. A batch is only a grouping label over ordinary
 * `event_discount_codes` rows (one Discount Code entity; a one-time code is
 * max_uses = 1), so registration-time validation needs no change.
 */
final class DiscountCodeBatchRepository extends Repository
{
    public const MAX_COUNT = 1000;
    public const MAX_PREFIX_LENGTH = 20;
    public const SUFFIX_LENGTH = 8;
    /** No 0/O/1/I/L: codes get read out and typed by hand. */
    public const CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> batch (camelCase) with `codes`
     */
    public function generate(string $eventId, array $input, ?string $createdBy): array
    {
        $params = $this->validate($input);
        $batchId = $this->id();

        $this->pdo->beginTransaction();
        try {
            $taken = $this->takenCodes($eventId);
            $codes = $this->makeCodes($params['prefix'], $params['count'], $taken);
            $label = $params['label'] !== ''
                ? $params['label']
                : ($params['prefix'] !== '' ? $params['prefix'] : 'Partia') . ' ' . date('Y-m-d H:i');

            $this->pdo->prepare(
                'INSERT INTO event_discount_code_batches (id, event_id, label, prefix, code_count, discount_type, discount_value, max_uses, valid_from, valid_to, created_by)
                 VALUES (:id, :eventId, :label, :prefix, :count, :type, :value, :maxUses, :validFrom, :validTo, :createdBy)'
            )->execute([
                'id' => $batchId,
                'eventId' => $eventId,
                'label' => $label,
                'prefix' => $params['prefix'],
                'count' => $params['count'],
                'type' => $params['discountType'],
                'value' => $params['discountValue'],
                'maxUses' => $params['maxUses'],
                'validFrom' => $params['validFrom'],
                'validTo' => $params['validTo'],
                'createdBy' => $createdBy !== null && $createdBy !== '' ? $createdBy : null,
            ]);

            $sortStmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM event_discount_codes WHERE event_id = :eventId');
            $sortStmt->execute(['eventId' => $eventId]);
            $sort = (int) $sortStmt->fetchColumn();

            $insert = $this->pdo->prepare(
                'INSERT INTO event_discount_codes (id, event_id, code, discount_type, discount_value, valid_from, valid_to, max_uses, used_count, distance_ids, active, used_by, sort_order, batch_id)
                 VALUES (:id, :eventId, :code, :type, :value, :validFrom, :validTo, :maxUses, 0, NULL, 1, NULL, :sort, :batchId)'
            );
            foreach ($codes as $code) {
                $insert->execute([
                    'id' => $this->id(),
                    'eventId' => $eventId,
                    'code' => $code,
                    'type' => $params['discountType'],
                    'value' => $params['discountValue'],
                    'validFrom' => $params['validFrom'],
                    'validTo' => $params['validTo'],
                    'maxUses' => $params['maxUses'],
                    'sort' => $sort++,
                    'batchId' => $batchId,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($eventId, $batchId) ?? throw new \RuntimeException('discount_batch_missing_after_insert');
    }

    /** @return list<array<string, mixed>> batches of the event, newest first, with usage totals */
    public function listForEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, COALESCE(SUM(c.used_count), 0) AS used_total, COUNT(c.id) AS codes_total
             FROM event_discount_code_batches b
             LEFT JOIN event_discount_codes c ON c.batch_id = b.id
             WHERE b.event_id = :eventId
             GROUP BY b.id
             ORDER BY b.created_at DESC, b.id ASC'
        );
        $stmt->execute(['eventId' => $eventId]);

        return array_values(array_map(fn (array $row): array => $this->present($row), $stmt->fetchAll()));
    }

    /** @return array<string, mixed>|null batch with `codes`, scoped to the event (a foreign id is "not found") */
    public function find(string $eventId, string $batchId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_discount_code_batches WHERE id = :id AND event_id = :eventId');
        $stmt->execute(['id' => $batchId, 'eventId' => $eventId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $codes = $this->pdo->prepare(
            'SELECT id, code, used_count, active FROM event_discount_codes WHERE batch_id = :batchId AND event_id = :eventId ORDER BY sort_order ASC, id ASC'
        );
        $codes->execute(['batchId' => $batchId, 'eventId' => $eventId]);
        $list = [];
        foreach ($codes->fetchAll() as $c) {
            $list[] = [
                'id' => (string) $c['id'],
                'code' => (string) $c['code'],
                'usedCount' => (int) $c['used_count'],
                'active' => (bool) $c['active'],
            ];
        }

        $row['used_total'] = array_sum(array_column($list, 'usedCount'));
        $row['codes_total'] = count($list);
        $batch = $this->present($row);
        $batch['codes'] = $list;

        return $batch;
    }

    /**
     * Rows for the CSV export. User-supplied text (label, dates) goes through csvCell() against
     * spreadsheet formula injection; codes are built from a fixed charset plus a validated prefix.
     *
     * @param array<string, mixed> $batch result of find()
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    public static function exportRows(array $batch): array
    {
        $columns = [
            ['key' => 'code', 'label' => 'Kod'],
            ['key' => 'type', 'label' => 'Rodzaj rabatu'],
            ['key' => 'value', 'label' => 'Wartość rabatu'],
            ['key' => 'maxUses', 'label' => 'Limit użyć'],
            ['key' => 'usedCount', 'label' => 'Użycia'],
            ['key' => 'validFrom', 'label' => 'Ważny od'],
            ['key' => 'validTo', 'label' => 'Ważny do'],
            ['key' => 'batch', 'label' => 'Partia'],
        ];
        $rows = [];
        foreach ((array) $batch['codes'] as $code) {
            $rows[] = [
                'code' => self::csvCell((string) $code['code']),
                'type' => $batch['discountType'] === 'percent' ? 'procent' : 'kwota',
                'value' => (string) $batch['discountValue'],
                'maxUses' => (string) $batch['maxUses'],
                'usedCount' => (string) $code['usedCount'],
                'validFrom' => self::csvCell((string) ($batch['validFrom'] ?? '')),
                'validTo' => self::csvCell((string) ($batch['validTo'] ?? '')),
                'batch' => self::csvCell((string) $batch['label']),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** Neutralises spreadsheet formula injection (leading = + - @ tab CR) in user-supplied text. */
    public static function csvCell(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{prefix: string, count: int, label: string, discountType: string, discountValue: string, maxUses: int, validFrom: ?string, validTo: ?string}
     */
    private function validate(array $input): array
    {
        $prefix = strtoupper(trim((string) ($input['prefix'] ?? '')));
        if ($prefix !== '' && (strlen($prefix) > self::MAX_PREFIX_LENGTH || preg_match('/^[A-Z0-9][A-Z0-9-]*$/', $prefix) !== 1)) {
            throw new InputValidationException('Prefiks może mieć do ' . self::MAX_PREFIX_LENGTH . ' znaków: litery, cyfry i myślniki.', 'prefix');
        }

        $count = $input['count'] ?? null;
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            throw new InputValidationException('Podaj liczbę kodów (1–' . self::MAX_COUNT . ').', 'count');
        }
        $count = (int) $count;
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InputValidationException('Liczba kodów musi mieścić się w zakresie 1–' . self::MAX_COUNT . '.', 'count');
        }

        $label = trim((string) ($input['label'] ?? ''));
        if (mb_strlen($label) > 120) {
            throw new InputValidationException('Etykieta partii może mieć do 120 znaków.', 'label');
        }

        $type = $input['discountType'] ?? null;
        if (!in_array($type, ['percent', 'fixed'], true)) {
            throw new InputValidationException('Rodzaj rabatu musi być procentowy lub kwotowy.', 'discountType');
        }

        $value = $input['discountValue'] ?? null;
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new InputValidationException('Podaj wartość rabatu.', 'discountValue');
        }
        $value = round((float) $value, 2);
        if ($value <= 0 || ($type === 'percent' && $value > 100) || $value > 99999999.99) {
            throw new InputValidationException(
                $type === 'percent' ? 'Rabat procentowy musi mieścić się w zakresie 0–100.' : 'Wartość rabatu musi być większa od zera.',
                'discountValue'
            );
        }

        $maxUses = $input['maxUses'] ?? 1;
        if (!is_int($maxUses) && !(is_string($maxUses) && ctype_digit($maxUses))) {
            throw new InputValidationException('Limit użyć musi być liczbą całkowitą (min. 1).', 'maxUses');
        }
        $maxUses = (int) $maxUses;
        if ($maxUses < 1 || $maxUses > 1000000) {
            throw new InputValidationException('Limit użyć musi być liczbą całkowitą (min. 1).', 'maxUses');
        }

        $validFrom = $this->dateOrNull($input['validFrom'] ?? null, 'validFrom', 'Ważny od');
        $validTo = $this->dateOrNull($input['validTo'] ?? null, 'validTo', 'Ważny do');
        if ($validFrom !== null && $validTo !== null && strtotime($validFrom) > strtotime($validTo)) {
            throw new InputValidationException('Data końca ważności nie może być wcześniejsza niż początku.', 'validTo');
        }

        return [
            'prefix' => $prefix,
            'count' => $count,
            'label' => $label,
            'discountType' => (string) $type,
            'discountValue' => number_format($value, 2, '.', ''),
            'maxUses' => $maxUses,
            'validFrom' => $validFrom,
            'validTo' => $validTo,
        ];
    }

    private function dateOrNull(mixed $value, string $field, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 64 || strtotime($value) === false) {
            throw new InputValidationException($label . ': nieprawidłowa data.', $field);
        }

        return $value;
    }

    /** @return array<string, true> upper-cased codes already used in the event */
    private function takenCodes(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT UPPER(code) FROM event_discount_codes WHERE event_id = :eventId');
        $stmt->execute(['eventId' => $eventId]);
        $taken = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $code) {
            $taken[(string) $code] = true;
        }

        return $taken;
    }

    /**
     * @param array<string, true> $taken
     * @return list<string>
     */
    private function makeCodes(string $prefix, int $count, array $taken): array
    {
        $codes = [];
        $max = strlen(self::CHARSET) - 1;
        $attempts = 0;
        while (count($codes) < $count) {
            if (++$attempts > $count * 20 + 100) {
                throw new \RuntimeException('discount_batch_code_space_exhausted');
            }
            $suffix = '';
            for ($i = 0; $i < self::SUFFIX_LENGTH; $i++) {
                $suffix .= self::CHARSET[random_int(0, $max)];
            }
            $code = $prefix !== '' ? $prefix . '-' . $suffix : $suffix;
            if (isset($taken[$code])) {
                continue;
            }
            $taken[$code] = true;
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'eventId' => (string) $row['event_id'],
            'label' => (string) $row['label'],
            'prefix' => (string) $row['prefix'],
            'count' => (int) $row['code_count'],
            'discountType' => (string) $row['discount_type'],
            'discountValue' => (float) $row['discount_value'],
            'maxUses' => (int) $row['max_uses'],
            'validFrom' => $row['valid_from'] !== null ? (string) $row['valid_from'] : null,
            'validTo' => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
            'createdBy' => $row['created_by'] !== null ? (string) $row['created_by'] : null,
            'createdAt' => (string) $row['created_at'],
            'usedTotal' => (int) ($row['used_total'] ?? 0),
            'codesTotal' => (int) ($row['codes_total'] ?? $row['code_count']),
        ];
    }
}
