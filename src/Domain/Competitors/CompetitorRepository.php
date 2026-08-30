<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

use ZmierzymyCzas\Domain\Repository;

/**
 * Zawodnik: profil danych startowych, byt samodzielny — nie własność Konta.
 * Może istnieć bez żadnego powiązanego Konta (np. dziecko zarejestrowane przez rodzica).
 */
final class CompetitorRepository extends Repository
{
    private const COLUMNS = <<<'SQL'
id, first_name AS firstName, last_name AS lastName, birth_date AS birthDate, gender,
email, phone, club, user_id AS userId, created_at AS createdAt, updated_at AS updatedAt
SQL;

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM competitors WHERE user_id = :userId');
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Zwraca istniejącego Zawodnika powiązanego z Kontem, tworząc go w locie,
     * jeśli konto powstało przed wdrożeniem tego modelu (np. w testach) i jeszcze
     * nie ma swojego profilu Zawodnika.
     *
     * @param array<string, mixed> $user
     */
    public function ensureForUser(array $user): array
    {
        $existing = $this->findByUserId((string) $user['id']);
        if ($existing !== null) {
            return $existing;
        }

        $name = trim((string) ($user['name'] ?? ''));
        $spacePos = strrpos($name, ' ');
        $firstName = $spacePos === false ? $name : substr($name, 0, $spacePos);
        $lastName = $spacePos === false ? '' : substr($name, $spacePos + 1);

        return $this->create([
            'firstName' => $firstName !== '' ? $firstName : $name,
            'lastName' => $lastName,
            'birthDate' => $user['birthDate'] ?? null,
            'gender' => $user['gender'] ?? null,
            'email' => $user['email'] ?? null,
            'phone' => $user['phone'] ?? null,
            'club' => $user['club'] ?? null,
            'userId' => $user['id'],
        ], skipDuplicateCheck: true);
    }

    /**
     * Zawodnicy, którzy pasują imieniem, nazwiskiem i datą urodzenia — obrona przed
     * duplikatami jest ostrzeżeniem, nie blokadą (patrz E0.1 AC3).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDuplicates(string $firstName, string $lastName, ?string $birthDate, ?string $excludeId = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
            FROM competitors
            WHERE LOWER(first_name) = LOWER(:firstName)
              AND LOWER(last_name) = LOWER(:lastName)
              AND birth_date <=> :birthDate';
        $params = ['firstName' => $firstName, 'lastName' => $lastName, 'birthDate' => $birthDate];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :excludeId';
            $params['excludeId'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> Zawodnik wraz z listą potencjalnych duplikatów w kluczu "duplicateWarnings".
     */
    public function create(array $data, bool $skipDuplicateCheck = false): array
    {
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $birthDate = $data['birthDate'] ?? null;

        $duplicates = $skipDuplicateCheck ? [] : $this->findDuplicates($firstName, $lastName, $birthDate);

        $id = $this->id();
        $this->insert('competitors', [
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'gender' => $data['gender'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'club' => $data['club'] ?? null,
            'user_id' => $data['userId'] ?? null,
        ]);

        $created = $this->find($id) ?? [];
        $created['duplicateWarnings'] = $duplicates;
        return $created;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $mapped = [];
        foreach ([
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'birthDate' => 'birth_date',
            'gender' => 'gender',
            'email' => 'email',
            'phone' => 'phone',
            'club' => 'club',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        $this->updateById('competitors', $id, $mapped);
        return $this->find($id);
    }
}
