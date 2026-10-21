<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use PDO;
use ZmierzymyCzas\Support\InputValidationException;

/**
 * R-13 (contract 5.1): start (bib) number ranges per distance, automatic assignment inside
 * the range and the manual override with uniqueness within the Event.
 *
 * Numbers are compared numerically when both sides are digits ("007" == "7"), otherwise as
 * case-insensitive strings. Assigned numbers are zero-padded to 3 digits (as the organizer UI
 * always did).
 */
final class BibNumberService
{
    public const ERROR_RANGE_OVERLAP = 'bib_range_overlap';
    public const ERROR_RANGE_INVALID = 'bib_range_invalid';
    public const ERROR_OUT_OF_RANGE = 'bib_out_of_range';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Validates the ranges of one Event's distances (already-normalized columns, in payload order).
     *
     * @param list<array<string, mixed>> $distanceColumns each with name, bib_range_from, bib_range_to
     * @throws InputValidationException field = "distances[i].bibRangeFrom|bibRangeTo"
     */
    public static function validateRanges(array $distanceColumns): void
    {
        $ranges = [];
        foreach ($distanceColumns as $i => $columns) {
            $from = $columns['bib_range_from'] ?? null;
            $to = $columns['bib_range_to'] ?? null;
            if ($from === null && $to === null) {
                continue;
            }
            $path = 'distances[' . $i . ']';
            if ($from === null || $to === null) {
                throw new InputValidationException(
                    'Zakres numerów startowych wymaga podania obu wartości: od i do.',
                    $path . ($from === null ? '.bibRangeFrom' : '.bibRangeTo'),
                    self::ERROR_RANGE_INVALID
                );
            }
            if ((int) $from < 1 || (int) $to < (int) $from) {
                throw new InputValidationException(
                    'Zakres numerów startowych: „od” musi być co najmniej 1 i nie większe niż „do”.',
                    $path . '.bibRangeTo',
                    self::ERROR_RANGE_INVALID
                );
            }
            foreach ($ranges as [$otherFrom, $otherTo, $otherName]) {
                if ((int) $from <= $otherTo && (int) $to >= $otherFrom) {
                    throw new InputValidationException(
                        'Zakres numerów startowych dystansu „' . (string) ($columns['name'] ?? '') . '” (' . (int) $from . '–' . (int) $to
                        . ') nachodzi na zakres dystansu „' . $otherName . '” (' . $otherFrom . '–' . $otherTo . ').',
                        $path . '.bibRangeFrom',
                        self::ERROR_RANGE_OVERLAP
                    );
                }
            }
            $ranges[] = [(int) $from, (int) $to, (string) ($columns['name'] ?? '')];
        }
    }

    /**
     * Assigns the next free number inside each distance's range to registrations that have none.
     *
     * @param list<string>|null $distanceIds null = every distance of the Event that has a range
     * @param string $order 'registered' (registration time) or 'name'
     * @return array{assigned: int, assignments: list<array{registrationId: string, bibNumber: string}>, skipped: list<array{distanceId: string, reason: string, count: int}>}
     */
    public function assign(string $eventId, ?array $distanceIds, string $order, bool $paidOnly): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockEvent($eventId);
            $stmt = $this->pdo->prepare(
                'SELECT id, bib_range_from, bib_range_to FROM event_distances WHERE event_id = :e ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute(['e' => $eventId]);
            $distances = $stmt->fetchAll();

            $used = $this->usedNumbers($eventId);
            $assignments = [];
            $skipped = [];
            $orderBy = $order === 'name' ? 'user_name ASC, registered_at ASC, id ASC' : 'registered_at ASC, id ASC';
            $statuses = $paidOnly ? "('confirmed')" : "('pending','confirmed')";

            foreach ($distances as $distance) {
                $distanceId = (string) $distance['id'];
                if ($distanceIds !== null && !in_array($distanceId, $distanceIds, true)) {
                    continue;
                }
                $regs = $this->pdo->prepare(
                    "SELECT id FROM registrations WHERE event_id = :e AND distance_id = :d AND status IN {$statuses}
                     AND (bib_number IS NULL OR bib_number = '') ORDER BY {$orderBy}"
                );
                $regs->execute(['e' => $eventId, 'd' => $distanceId]);
                $ids = $regs->fetchAll(PDO::FETCH_COLUMN);
                if ($ids === []) {
                    continue;
                }
                if ($distance['bib_range_from'] === null || $distance['bib_range_to'] === null) {
                    $skipped[] = ['distanceId' => $distanceId, 'reason' => 'no_range', 'count' => count($ids)];
                    continue;
                }
                $next = (int) $distance['bib_range_from'];
                $to = (int) $distance['bib_range_to'];
                $update = $this->pdo->prepare('UPDATE registrations SET bib_number = :b, updated_at = :u WHERE id = :id');
                $left = count($ids);
                foreach ($ids as $registrationId) {
                    while ($next <= $to && isset($used[$next])) {
                        $next++;
                    }
                    if ($next > $to) {
                        break;
                    }
                    $bib = str_pad((string) $next, 3, '0', STR_PAD_LEFT);
                    $update->execute(['b' => $bib, 'u' => date(DATE_ATOM), 'id' => $registrationId]);
                    $used[$next] = (string) $registrationId;
                    $assignments[] = ['registrationId' => (string) $registrationId, 'bibNumber' => $bib];
                    $left--;
                    $next++;
                }
                if ($left > 0) {
                    $skipped[] = ['distanceId' => $distanceId, 'reason' => 'range_exhausted', 'count' => $left];
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['assigned' => count($assignments), 'assignments' => $assignments, 'skipped' => $skipped];
    }

    /**
     * Validates a manual number (or clearing it with null/'') for one registration:
     * inside the distance's range (when it has one) and unique within the Event.
     *
     * @return string|null the normalized value to store
     * @throws InputValidationException 422 out of range
     * @throws BibNumberTakenException 409
     */
    public function checkManual(string $registrationId, mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InputValidationException('Numer startowy ma nieprawidłowy format.', 'bibNumber');
        }
        $bib = trim((string) $value);
        if (mb_strlen($bib) > 32) {
            throw new InputValidationException('Numer startowy może mieć najwyżej 32 znaki.', 'bibNumber');
        }
        $reg = $this->pdo->prepare(
            'SELECT r.event_id, d.bib_range_from, d.bib_range_to FROM registrations r
             LEFT JOIN event_distances d ON d.id = r.distance_id WHERE r.id = :id'
        );
        $reg->execute(['id' => $registrationId]);
        $row = $reg->fetch();
        if ($row === false) {
            return $bib;
        }
        if ($row['bib_range_from'] !== null && $row['bib_range_to'] !== null) {
            if (!ctype_digit($bib) || (int) $bib < (int) $row['bib_range_from'] || (int) $bib > (int) $row['bib_range_to']) {
                throw new InputValidationException(
                    'Numer startowy musi mieścić się w zakresie dystansu: ' . (int) $row['bib_range_from'] . '–' . (int) $row['bib_range_to'] . '.',
                    'bibNumber',
                    self::ERROR_OUT_OF_RANGE
                );
            }
        }
        $holder = $this->holderOf((string) $row['event_id'], $bib, $registrationId);
        if ($holder !== null) {
            throw new BibNumberTakenException($bib, $holder);
        }

        return $bib;
    }

    /** Registration id (other than $exceptId) of the same Event holding an equivalent number, or null. */
    private function holderOf(string $eventId, string $bib, string $exceptId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, bib_number FROM registrations WHERE event_id = :e AND id <> :x AND bib_number IS NOT NULL AND bib_number <> ''
             AND status NOT IN ('cancelled','removed')"
        );
        $stmt->execute(['e' => $eventId, 'x' => $exceptId]);
        foreach ($stmt->fetchAll() as $row) {
            if (self::sameBib((string) $row['bib_number'], $bib)) {
                return (string) $row['id'];
            }
        }

        return null;
    }

    private static function sameBib(string $a, string $b): bool
    {
        if (ctype_digit($a) && ctype_digit($b)) {
            return (int) $a === (int) $b;
        }

        return mb_strtolower($a) === mb_strtolower($b);
    }

    /** @return array<int, string> numeric bib => registration id (numeric values only; others cannot collide with a range) */
    private function usedNumbers(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, bib_number FROM registrations WHERE event_id = :e AND bib_number IS NOT NULL AND bib_number <> ''
             AND status NOT IN ('cancelled','removed')"
        );
        $stmt->execute(['e' => $eventId]);
        $used = [];
        foreach ($stmt->fetchAll() as $row) {
            if (ctype_digit((string) $row['bib_number'])) {
                $used[(int) $row['bib_number']] = (string) $row['id'];
            }
        }

        return $used;
    }

    private function lockEvent(string $eventId): void
    {
        $this->pdo->prepare('SELECT id FROM events WHERE id = :id FOR UPDATE')->execute(['id' => $eventId]);
    }
}
