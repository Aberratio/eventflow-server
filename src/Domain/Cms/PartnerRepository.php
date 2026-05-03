<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Cms;

use ZmierzymyCzas\Domain\Repository;

final class PartnerRepository extends Repository
{
    private const COLUMNS = 'id, name, logo_url AS logoUrl, website_url AS websiteUrl, featured';

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM partners ORDER BY sort_order ASC, name ASC');
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function featured(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM partners WHERE featured = 1 ORDER BY sort_order ASC, name ASC');
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $row['featured'] = (bool) $row['featured'];
        return $row;
    }
}
