<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Insurance;

use ZmierzymyCzas\Domain\Repository;

final class InsuranceRepository extends Repository
{
    private const DEFAULT = [
        'baseFee' => 15.0,
        'ageMultiplier' => 0.5,
        'maxAge' => 70,
        'infoHtml' => '',
    ];

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        $stmt = $this->pdo->query('SELECT base_fee, age_multiplier, max_age, info_html FROM insurance_settings LIMIT 1');
        $row = $stmt->fetch();
        if (!$row) {
            return self::DEFAULT;
        }
        return [
            'baseFee' => (float) $row['base_fee'],
            'ageMultiplier' => (float) $row['age_multiplier'],
            'maxAge' => (int) $row['max_age'],
            'infoHtml' => (string) $row['info_html'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function updateSettings(array $data): array
    {
        $current = $this->getSettings();
        $next = [...$current, ...$data];

        $stmt = $this->pdo->query('SELECT id FROM insurance_settings LIMIT 1');
        $row = $stmt->fetch();

        if ($row) {
            $this->pdo->prepare('UPDATE insurance_settings SET base_fee = :baseFee, age_multiplier = :ageMultiplier, max_age = :maxAge, info_html = :infoHtml WHERE id = :id')
                ->execute([
                    'baseFee' => $next['baseFee'],
                    'ageMultiplier' => $next['ageMultiplier'],
                    'maxAge' => $next['maxAge'],
                    'infoHtml' => $next['infoHtml'],
                    'id' => $row['id'],
                ]);
        } else {
            $this->insert('insurance_settings', [
                'id' => $this->id(),
                'base_fee' => $next['baseFee'],
                'age_multiplier' => $next['ageMultiplier'],
                'max_age' => $next['maxAge'],
                'info_html' => $next['infoHtml'],
            ]);
        }

        return $this->getSettings();
    }
}
