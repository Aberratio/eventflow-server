<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Admin;

use ZmierzymyCzas\Domain\Repository;

final class AdminRepository extends Repository
{
    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    public function activityLogs(array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['action' => 'action', 'userId' => 'user_id'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (!empty($filters['dateFrom'])) {
            $where[] = 'timestamp >= :dateFrom';
            $params['dateFrom'] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where[] = 'timestamp <= :dateTo';
            $params['dateTo'] = $filters['dateTo'];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare("SELECT id, user_id AS userId, action, description, target_type AS targetType, target_id AS targetId, target_name AS targetName, ip_address AS ipAddress, user_agent AS userAgent, timestamp FROM activity_logs{$whereSql} ORDER BY timestamp DESC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function securitySettings(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM security_settings LIMIT 1');
        $row = $stmt->fetch() ?: [
            'two_factor_enabled' => 0,
            'two_factor_method' => null,
            'last_password_change' => date(DATE_ATOM),
            'password_expiry_days' => 90,
            'session_timeout_minutes' => 60,
            'trusted_devices' => '[]',
            'login_history' => '[]',
        ];
        return [
            'twoFactorEnabled' => (bool) $row['two_factor_enabled'],
            'twoFactorMethod' => $row['two_factor_method'],
            'lastPasswordChange' => $row['last_password_change'],
            'passwordExpiryDays' => (int) $row['password_expiry_days'],
            'sessionTimeoutMinutes' => (int) $row['session_timeout_minutes'],
            'trustedDevices' => json_decode((string) $row['trusted_devices'], true) ?: [],
            'loginHistory' => json_decode((string) $row['login_history'], true) ?: [],
        ];
    }

    /** @param array<string, mixed> $data */
    public function updateSecuritySettings(array $data): array
    {
        $current = $this->securitySettings();
        $next = [...$current, ...$data];
        $this->pdo->exec('DELETE FROM security_settings');
        $this->insert('security_settings', [
            'id' => $this->id(),
            'two_factor_enabled' => (int) ($next['twoFactorEnabled'] ?? false),
            'two_factor_method' => $next['twoFactorMethod'] ?? null,
            'last_password_change' => $next['lastPasswordChange'] ?? date(DATE_ATOM),
            'password_expiry_days' => $next['passwordExpiryDays'] ?? 90,
            'session_timeout_minutes' => $next['sessionTimeoutMinutes'] ?? 60,
            'trusted_devices' => json_encode($next['trustedDevices'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'login_history' => json_encode($next['loginHistory'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $this->securitySettings();
    }

    /** @return array<int, array<string, mixed>> */
    public function onlineSessions(): array
    {
        $stmt = $this->pdo->query('SELECT user_id AS userId, user_name AS userName, role, login_at AS loginAt, last_active_at AS lastActiveAt, ip_address AS ipAddress, browser, location FROM online_sessions ORDER BY last_active_at DESC');
        return $stmt->fetchAll();
    }
}
