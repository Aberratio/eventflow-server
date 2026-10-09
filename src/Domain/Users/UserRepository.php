<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Users;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Repository;

final class UserRepository extends Repository
{
    // organizerPackageCredits (E5.6, migration 053) is intentionally read-only here —
    // update() below has no matching input key, since the only writers are
    // OrderRepository::create() (purchase) and EventRepository::submit()/reject()
    // (consume/refund). Exposed on every read as a seam for E5.7 (purchase ->
    // Organizer role) and for a future client "your credits" display, without this
    // ticket building either.
    private const PUBLIC_COLUMNS = 'id, name, email, (email_verified_at IS NOT NULL) AS emailVerified, role, avatar, phone, birth_year AS birthYear, birth_date AS birthDate, gender, club, company_name AS companyName, nip, website, description, address, sos_contacts AS sosContacts, status, event_limit AS eventLimit, organizer_package_credits AS organizerPackageCredits';

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['role'])) {
            $where[] = 'role = :role';
            $params['role'] = $filters['role'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :searchName OR email LIKE :searchEmail)';
            $search = '%' . $filters['search'] . '%';
            $params['searchName'] = $search;
            $params['searchEmail'] = $search;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagination = $this->pagination($filters);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM users{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . " FROM users{$whereSql} ORDER BY name LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => array_map(fn (array $row) => $this->hydrate($row) ?? $row, $stmt->fetchAll()),
            'total' => $total,
            'page' => $pagination['page'],
            'pageSize' => $pagination['pageSize'],
        ];
    }

    /** @param array<string, mixed>|false $row */
    private function hydrate(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        if (array_key_exists('emailVerified', $row)) {
            $row['emailVerified'] = (bool) $row['emailVerified'];
        }
        if (isset($row['address']) && is_string($row['address'])) {
            $row['address'] = json_decode($row['address'], true);
        }
        if (isset($row['sosContacts']) && is_string($row['sosContacts'])) {
            $row['sosContacts'] = json_decode($row['sosContacts'], true);
        }
        return $row;
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $this->hydrate($stmt->fetch());
    }

    public function findByEmail(string $email, bool $withPassword = false): ?array
    {
        $columns = $withPassword ? self::PUBLIC_COLUMNS . ', password_hash AS passwordHash' : self::PUBLIC_COLUMNS;
        $stmt = $this->pdo->prepare("SELECT {$columns} FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        return $this->hydrate($stmt->fetch());
    }

    public function passwordHashById(string $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) ? $hash : null;
    }

    /** PP.7: the session version a JWT must carry; never part of any DTO. 0 for an unknown user. */
    public function tokenVersion(string $id): int
    {
        $stmt = $this->pdo->prepare('SELECT token_version FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $version = $stmt->fetchColumn();

        return $version === false ? 0 : (int) $version;
    }

    /** PP.7: invalidates every session of the user that carries an older version. Returns the new version. */
    public function bumpTokenVersion(string $id): int
    {
        $this->pdo->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = :id')->execute(['id' => $id]);

        return $this->tokenVersion($id);
    }

    /** PP.70: users.role ENUM (001_init.sql). */
    public const ROLES = ['participant', 'organizer', 'moderator', 'admin', 'superadmin'];
    /** PP.70: users.status ENUM (001_init.sql). */
    public const STATUSES = ['active', 'blocked'];

    /**
     * PP.70: sets the role and ends every session of the account in ONE statement (atomic).
     * False when the role is unchanged or the account does not exist.
     */
    public function changeRoleEndingSessions(string $id, string $role): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET role = :role, token_version = token_version + 1 WHERE id = :id AND role <> :unchangedRole'
        );
        $stmt->execute(['role' => $role, 'id' => $id, 'unchangedRole' => $role]);

        return $stmt->rowCount() === 1;
    }

    /** PP.70: same for the status (block/unblock). False when unchanged or no such account. */
    public function changeStatusEndingSessions(string $id, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET status = :status, token_version = token_version + 1 WHERE id = :id AND status <> :unchangedStatus'
        );
        $stmt->execute(['status' => $status, 'id' => $id, 'unchangedStatus' => $status]);

        return $stmt->rowCount() === 1;
    }

    /** PP.78: a staff e-mail edit; the new address is unverified and every session ends. */
    public function unverifyEmailEndingSessions(string $id): void
    {
        $this->pdo->prepare('UPDATE users SET email_verified_at = NULL, token_version = token_version + 1 WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** PP.7: marks the address verified; keeps the first verification time. */
    public function markEmailVerified(string $id): void
    {
        $this->pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** PP.7: applies a confirmed self e-mail change; the new address counts as verified. PDOException 23000 = address taken. */
    public function changeEmailVerified(string $id, string $email): void
    {
        $this->pdo->prepare('UPDATE users SET email = :email, email_verified_at = NOW() WHERE id = :id')
            ->execute(['email' => $email, 'id' => $id]);
    }

    public function setPassword(string $id, string $plainPassword): void
    {
        $this->updateById('users', $id, ['password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT)]);
    }

    /**
     * PP.82: an account nobody can sign in to until the owner sets a password through a link.
     * A real bcrypt hash (same algorithm and cost as every other account) of 32 random bytes that
     * are discarded: password_verify() never matches, and it takes as long as for any other account,
     * so the login response time does not reveal accounts without a password.
     */
    public static function unusablePasswordHash(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $password = $data['password'] ?? null;
        $passwordHash = is_string($password) && $password !== ''
            ? password_hash($password, PASSWORD_DEFAULT)
            : self::unusablePasswordHash();
        $this->insert('users', [
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => $passwordHash,
            'role' => $data['role'] ?? 'participant',
            'phone' => $data['phone'] ?? null,
            'birth_year' => $data['birthYear'] ?? null,
            'birth_date' => $data['birthDate'] ?? null,
            'gender' => $data['gender'] ?? null,
            'club' => $data['club'] ?? null,
            'company_name' => $data['companyName'] ?? null,
            'nip' => $data['nip'] ?? null,
            'website' => $data['website'] ?? null,
            'description' => $data['description'] ?? null,
            'address' => isset($data['address']) ? json_encode($data['address']) : null,
            'sos_contacts' => isset($data['sosContacts']) ? json_encode($data['sosContacts']) : null,
            'status' => $data['status'] ?? 'active',
            'event_limit' => $data['eventLimit'] ?? 0,
        ]);

        $created = $this->find($id);
        if ($created !== null) {
            // Każde Konto dostaje własny profil Zawodnika (E0.1) — Zawodnik jest bytem
            // samodzielnym, ale Konto z rolą "participant" zwykle biega samo za siebie.
            (new CompetitorRepository($this->pdo))->ensureForUser($created);
        }

        return $created;
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $mapped = [];
        foreach ([
            'name' => 'name',
            'email' => 'email',
            'role' => 'role',
            'avatar' => 'avatar',
            'phone' => 'phone',
            'birthYear' => 'birth_year',
            'birthDate' => 'birth_date',
            'gender' => 'gender',
            'club' => 'club',
            'companyName' => 'company_name',
            'nip' => 'nip',
            'website' => 'website',
            'description' => 'description',
            'status' => 'status',
            'eventLimit' => 'event_limit',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        if (array_key_exists('address', $data)) {
            $mapped['address'] = $data['address'] !== null ? json_encode($data['address']) : null;
        }
        if (array_key_exists('sosContacts', $data)) {
            $mapped['sos_contacts'] = $data['sosContacts'] !== null ? json_encode($data['sosContacts']) : null;
        }
        $this->updateById('users', $id, $mapped);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }
}
