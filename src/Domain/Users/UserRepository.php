<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Users;

use ZmierzymyCzas\Domain\Repository;

final class UserRepository extends Repository
{
    private const PUBLIC_COLUMNS = 'id, name, email, role, avatar, phone, birth_year AS birthYear, gender, club, company_name AS companyName, nip, website, description, status';

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
            $where[] = '(name LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
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
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $pagination['page'],
            'pageSize' => $pagination['pageSize'],
        ];
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email, bool $withPassword = false): ?array
    {
        $columns = $withPassword ? self::PUBLIC_COLUMNS . ', password_hash AS passwordHash' : self::PUBLIC_COLUMNS;
        $stmt = $this->pdo->prepare("SELECT {$columns} FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->insert('users', [
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => password_hash((string) ($data['password'] ?? 'demo1234'), PASSWORD_DEFAULT),
            'role' => $data['role'] ?? 'participant',
            'phone' => $data['phone'] ?? null,
            'birth_year' => $data['birthYear'] ?? null,
            'gender' => $data['gender'] ?? null,
            'club' => $data['club'] ?? null,
            'company_name' => $data['companyName'] ?? null,
            'nip' => $data['nip'] ?? null,
            'website' => $data['website'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        return $this->find($id);
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
            'gender' => 'gender',
            'club' => 'club',
            'companyName' => 'company_name',
            'nip' => 'nip',
            'website' => 'website',
            'description' => 'description',
            'status' => 'status',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        $this->updateById('users', $id, $mapped);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }
}
