<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

/**
 * PP.4: what a caller may learn about *other* Zawodnik profiles that look like a
 * duplicate: that they exist, and the name/birth date the caller typed anyway.
 * Never contact data, never the identity code (a bearer secret, spec 11 section 2).
 */
final class CompetitorDuplicatePresenter
{
    /**
     * @param array<int, array<string, mixed>> $rows CompetitorRepository::COLUMNS rows
     * @return list<array{id: string, firstName: string, lastName: string, birthDate: ?string}>
     */
    public static function present(array $rows): array
    {
        return array_values(array_map(static fn (array $row): array => [
            'id' => (string) ($row['id'] ?? ''),
            'firstName' => (string) ($row['firstName'] ?? ''),
            'lastName' => (string) ($row['lastName'] ?? ''),
            'birthDate' => isset($row['birthDate']) ? (string) $row['birthDate'] : null,
        ], $rows));
    }
}
