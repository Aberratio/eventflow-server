<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

use ZmierzymyCzas\Domain\Repository;

/**
 * The global, admin-configurable "points per place"
 * table a Wyzwanie's `point_threshold` node converts an Event placement into
 * points with — a descending point system, configurable globally and not
 * part of the model (explicitly NOT hardcoded logic, see migration 055's own
 * comment for why this is a single JSON-blob row rather than a per-place
 * catalog table, mirroring `payment_config`/original `insurance_settings`).
 *
 * Singleton settings row, same read/write shape as
 * InsuranceRepository::getSettings()/updateSettings() (the established
 * precedent for "one global admin-configurable settings row" in this repo).
 */
final class ChallengePointsSettingsRepository extends Repository
{
    private const FIXED_ID = '00000000-0000-0000-0000-000000000002';

    /** @var array{pointsTable: list<int>, floorPoints: int} */
    private const DEFAULT = [
        'pointsTable' => [100, 95, 90, 85, 80, 75, 70, 65, 60, 55, 50, 45, 40, 35, 30, 25, 20, 15, 10, 5],
        'floorPoints' => 0,
    ];

    /** @return array{pointsTable: list<int>, floorPoints: int} */
    public function getSettings(): array
    {
        $stmt = $this->pdo->query('SELECT points_table AS pointsTable, floor_points AS floorPoints FROM challenge_points_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();
        if (!$row) {
            return self::DEFAULT;
        }
        return [
            'pointsTable' => array_map('intval', (array) json_decode((string) $row['pointsTable'], true)),
            'floorPoints' => (int) $row['floorPoints'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{pointsTable: list<int>, floorPoints: int}
     */
    public function updateSettings(array $data): array
    {
        $current = $this->getSettings();
        $pointsTable = array_key_exists('pointsTable', $data) ? $this->sanitizeTable($data['pointsTable']) : $current['pointsTable'];
        $floorPoints = array_key_exists('floorPoints', $data) ? max(0, (int) $data['floorPoints']) : $current['floorPoints'];

        $stmt = $this->pdo->query('SELECT id FROM challenge_points_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();
        $encoded = json_encode(array_values($pointsTable), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($row) {
            $this->pdo->prepare('UPDATE challenge_points_settings SET points_table = :pointsTable, floor_points = :floorPoints WHERE id = :id')
                ->execute(['pointsTable' => $encoded, 'floorPoints' => $floorPoints, 'id' => $row['id']]);
        } else {
            $this->insert('challenge_points_settings', [
                'id' => self::FIXED_ID,
                'points_table' => $encoded,
                'floor_points' => $floorPoints,
            ]);
        }

        return $this->getSettings();
    }

    /**
     * Points awarded for a 1-based classification place. Places beyond the
     * table's length (or a place < 1, e.g. no result / disqualified) fall
     * back to `floorPoints` — never a hard error, since the table's whole
     * point is that admins can make it shorter than the largest field size
     * without breaking scoring for finishers past its end.
     */
    public function pointsForPlace(int $place, ?array $settings = null): int
    {
        $settings ??= $this->getSettings();
        if ($place < 1) {
            return 0;
        }
        return $settings['pointsTable'][$place - 1] ?? $settings['floorPoints'];
    }

    /** @return list<int> */
    private function sanitizeTable(mixed $table): array
    {
        if (!is_array($table)) {
            throw new \InvalidArgumentException('Tabela punktacji musi byc lista liczb.');
        }
        return array_map(static fn (mixed $v): int => max(0, (int) $v), array_values($table));
    }
}
