<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Insurance;

use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Support\HtmlSanitizer;

/**
 * NNW (insurance) is a catalog of variants, not a single global setting (E0.3):
 * admin defines variants — each with its own name and premium-formula parameters
 * (base fee, age multiplier, max age) — and organizers pick which ones are offered
 * on their event. `getSettings()`/`updateSettings()` remain as a thin read/write on
 * the first catalog row so the pre-existing single-settings contract keeps working
 * while the catalog CRUD (`list`/`create`/`update`/`delete`) is the real model.
 */
final class InsuranceRepository extends Repository
{
    private const DEFAULT = [
        'baseFee' => 15.0,
        'ageMultiplier' => 0.5,
        'maxAge' => 70,
        'infoHtml' => '',
    ];

    private const COLUMNS = 'id, name, base_fee AS baseFee, age_multiplier AS ageMultiplier, max_age AS maxAge, info_html AS infoHtml, active, sort_order AS sortOrder';

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM insurance_settings ORDER BY sort_order ASC, id ASC');
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM insurance_settings WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->insert('insurance_settings', [
            'id' => $id,
            'name' => (string) ($data['name'] ?? ''),
            'base_fee' => $data['baseFee'] ?? self::DEFAULT['baseFee'],
            'age_multiplier' => $data['ageMultiplier'] ?? self::DEFAULT['ageMultiplier'],
            'max_age' => $data['maxAge'] ?? self::DEFAULT['maxAge'],
            'info_html' => HtmlSanitizer::clean((string) ($data['infoHtml'] ?? self::DEFAULT['infoHtml'])),
            'active' => (int) (bool) ($data['active'] ?? true),
            'sort_order' => (int) ($data['sortOrder'] ?? 0),
        ]);

        return $this->find($id);
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    public function update(string $id, array $data): ?array
    {
        $map = [
            'name' => 'name',
            'baseFee' => 'base_fee',
            'ageMultiplier' => 'age_multiplier',
            'maxAge' => 'max_age',
            'infoHtml' => 'info_html',
            'sortOrder' => 'sort_order',
        ];
        $mapped = [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        if (array_key_exists('active', $data)) {
            $mapped['active'] = (int) (bool) $data['active'];
        }
        if (array_key_exists('info_html', $mapped)) {
            $mapped['info_html'] = HtmlSanitizer::clean((string) $mapped['info_html']);
        }

        $this->updateById('insurance_settings', $id, $mapped);
        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM insurance_settings WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        $stmt = $this->pdo->query('SELECT base_fee, age_multiplier, max_age, info_html FROM insurance_settings ORDER BY sort_order ASC, id ASC LIMIT 1');
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
        $next['infoHtml'] = HtmlSanitizer::clean((string) $next['infoHtml']);

        $stmt = $this->pdo->query('SELECT id FROM insurance_settings ORDER BY sort_order ASC, id ASC LIMIT 1');
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
                'name' => 'Podstawowe',
                'base_fee' => $next['baseFee'],
                'age_multiplier' => $next['ageMultiplier'],
                'max_age' => $next['maxAge'],
                'info_html' => $next['infoHtml'],
            ]);
        }

        return $this->getSettings();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $row['baseFee'] = (float) $row['baseFee'];
        $row['ageMultiplier'] = (float) $row['ageMultiplier'];
        $row['maxAge'] = (int) $row['maxAge'];
        $row['active'] = (bool) $row['active'];
        $row['sortOrder'] = (int) $row['sortOrder'];
        return $row;
    }
}
