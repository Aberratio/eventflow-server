<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Admin;

use ZmierzymyCzas\Domain\Repository;

final class AdminRepository extends Repository
{
    /** Retention window (E0.8): entries older than this are neither returned nor kept. */
    private const RETENTION_MONTHS = 12;

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    public function activityLogs(array $filters = []): array
    {
        $this->pruneExpiredActivityLogs();

        $where = ['timestamp >= :retentionCutoff'];
        $params = ['retentionCutoff' => $this->retentionCutoff()];
        foreach (['action' => 'action', 'userId' => 'user_id', 'targetType' => 'target_type', 'targetId' => 'target_id'] as $input => $column) {
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
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT id, user_id AS userId, action, description, target_type AS targetType, target_id AS targetId, target_name AS targetName, metadata, ip_address AS ipAddress, user_agent AS userAgent, timestamp FROM activity_logs{$whereSql} ORDER BY timestamp DESC");
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->decode($row, ['metadata']) ?? $row, $stmt->fetchAll());
    }

    /**
     * Retention: entries older than RETENTION_MONTHS are dropped lazily on read. No
     * scheduled-job runner exists in this codebase yet (checked: no cron/queue pattern
     * to hook into), so delete-on-read from the activityLogs() read path is the simplest
     * correct approach here — there is deliberately no endpoint to delete a single entry.
     */
    private function pruneExpiredActivityLogs(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM activity_logs WHERE timestamp < :cutoff');
        $stmt->execute(['cutoff' => $this->retentionCutoff()]);
    }

    private function retentionCutoff(): string
    {
        return (new \DateTimeImmutable('-' . self::RETENTION_MONTHS . ' months'))->format(DATE_ATOM);
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

    /** @param array<string, mixed> $data */
    /**
     * `metadata` (E8.1): the sole exception to Z2 ("źródłem prawdy jest
     * identyfikator, nie kopia") in this whole log — a nullable JSON blob for
     * event types where the source records themselves stop being editable
     * bytes after the action (today: only competitor merges), so the log
     * becomes the only place the pre-action state survives. Every other
     * action keeps passing null here and relying on targetId/targetType,
     * exactly as before.
     */
    public function insertActivityLog(array $data): void
    {
        $this->insert('activity_logs', $this->encode([
            'id' => $data['id'] ?? $this->id(),
            'user_id' => (string) ($data['userId'] ?? ''),
            'action' => (string) ($data['action'] ?? 'server_action'),
            'description' => (string) ($data['description'] ?? ''),
            'target_type' => $data['targetType'] ?? null,
            'target_id' => $data['targetId'] ?? null,
            'target_name' => $data['targetName'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'ip_address' => null,
            'user_agent' => null,
            'timestamp' => $data['timestamp'] ?? date(DATE_ATOM),
        ], ['metadata']));
    }

    /** @return array<int, array<string, mixed>> */
    public function onlineSessions(): array
    {
        $stmt = $this->pdo->query('SELECT user_id AS userId, user_name AS userName, role, login_at AS loginAt, last_active_at AS lastActiveAt, ip_address AS ipAddress, browser, location FROM online_sessions ORDER BY last_active_at DESC');
        return $stmt->fetchAll();
    }

    /**
     * E8.3 (spec 21 Answer §6): the minimal, explicitly-closed platform-admin
     * cross-section over Events/Organizers — "liczba Wydarzeń wg statusu
     * publikacyjnego z ticketu 08 [E1.1], liczba Organizatorów, liczba
     * aktywnych pakietów z ticketu 16 [E5.6]". Nothing beyond these three
     * numbers — the spec is explicit that no more is required.
     *
     * eventsByStatus: GROUP BY over the E1.1 `publication_status` axis,
     * excluding soft-deleted Events (`deleted_at IS NULL`) — same convention
     * as every other Event count in this repository (see byOrganizer() /
     * list()'s COUNT(*) above). Every enum value is always present, even at
     * zero, so a client can render a stable set of buckets without probing
     * for missing keys.
     *
     * organizerCount: `role = 'organizer'` exactly, not `staffRoles` — the
     * spec's plain reading ("liczba Organizatorów") means the Organizer
     * role, not staff who can *also* manage Events (moderator/admin/
     * superadmin already get their own visibility elsewhere in this panel).
     *
     * activePackageCredits: `organizer_package_credits` (E5.6) is a
     * materialized "right to submit one Event" counter, not a row-per-
     * package table — "aktywne pakiety" (packages not yet consumed by a
     * Draft -> Submitted transition, see 053_e5_6_organizer_package_credit.sql)
     * is read here as the *sum* of that counter across all users: each unit
     * of credit is literally one still-unconsumed package, so the sum is the
     * total count of active packages outstanding in the system right now —
     * a more literal reading of "liczba aktywnych pakietów" than "how many
     * distinct organizers happen to be holding at least one".
     *
     * @return array{eventsByStatus: array<int, array{status: string, count: int}>, organizerCount: int, activePackageCredits: int}
     */
    public function platformStats(): array
    {
        $statuses = ['draft', 'submitted', 'approved', 'published', 'finished', 'rejected', 'cancelled'];
        $stmt = $this->pdo->query(
            'SELECT publication_status AS status, COUNT(*) AS cnt FROM events WHERE deleted_at IS NULL GROUP BY publication_status'
        );
        $counts = array_column($stmt->fetchAll(), 'cnt', 'status');
        $eventsByStatus = array_map(static fn (string $status): array => [
            'status' => $status,
            'count' => (int) ($counts[$status] ?? 0),
        ], $statuses);

        $organizerCount = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'organizer'")->fetchColumn();

        $activePackageCredits = (int) $this->pdo->query('SELECT COALESCE(SUM(organizer_package_credits), 0) FROM users')->fetchColumn();

        return [
            'eventsByStatus' => $eventsByStatus,
            'organizerCount' => $organizerCount,
            'activePackageCredits' => $activePackageCredits,
        ];
    }
}
