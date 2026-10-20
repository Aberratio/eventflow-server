<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use ZmierzymyCzas\Domain\Repository;

/**
 * R-02: the per-Zapis change history (`registration_changes`, migration 130). One row per
 * self-service or organizer-driven change; the type column already covers the change of Dystans
 * and the transfer, so those only call record() with their own from/to snapshots.
 */
final class RegistrationChangeRepository extends Repository
{
    public const TYPE_CANCELLED = 'cancelled';
    public const TYPE_DISTANCE_CHANGED = 'distance_changed';
    public const TYPE_TRANSFERRED = 'transferred';

    /**
     * @param array<string, mixed>|null $from snapshot before the change
     * @param array<string, mixed>|null $to snapshot after the change
     * @param array{id?: string, role?: string} $actor
     */
    public function record(string $registrationId, string $type, ?array $from, ?array $to, array $actor): string
    {
        $id = $this->id();
        $this->insert('registration_changes', [
            'id' => $id,
            'registration_id' => $registrationId,
            'type' => $type,
            'from_json' => $from === null ? null : json_encode($from, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'to_json' => $to === null ? null : json_encode($to, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_id' => ($actor['id'] ?? '') !== '' ? (string) $actor['id'] : null,
            'actor_role' => ($actor['role'] ?? '') !== '' ? (string) $actor['role'] : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /**
     * Oldest first.
     *
     * @return array<int, array{id: string, registrationId: string, type: string, from: ?array<string, mixed>, to: ?array<string, mixed>, actorId: ?string, actorRole: ?string, createdAt: string}>
     */
    public function listForRegistration(string $registrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, registration_id, type, from_json, to_json, actor_id, actor_role, created_at
             FROM registration_changes WHERE registration_id = :id ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['id' => $registrationId]);

        return array_map(static function (array $row): array {
            $decode = static function (mixed $json): ?array {
                $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

                return is_array($decoded) ? $decoded : null;
            };

            return [
                'id' => (string) $row['id'],
                'registrationId' => (string) $row['registration_id'],
                'type' => (string) $row['type'],
                'from' => $decode($row['from_json']),
                'to' => $decode($row['to_json']),
                'actorId' => $row['actor_id'] !== null ? (string) $row['actor_id'] : null,
                'actorRole' => $row['actor_role'] !== null ? (string) $row['actor_role'] : null,
                'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))->format(DATE_ATOM),
            ];
        }, $stmt->fetchAll());
    }
}
