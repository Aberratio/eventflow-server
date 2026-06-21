<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Ticket;

use ZmierzymyCzas\Domain\Repository;

final class TicketRepository extends Repository
{
    /** @return array<int, array<string, mixed>> */
    public function byUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, registration_id AS registrationId, user_id AS userId, event_id AS eventId,
                    event_name AS eventName, distance_name AS distanceName,
                    ticket_number AS ticketNumber, qr_code AS qrCode, status,
                    issued_at AS issuedAt
             FROM tickets WHERE user_id = :userId ORDER BY issued_at DESC'
        );
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, registration_id AS registrationId, user_id AS userId, event_id AS eventId,
                    event_name AS eventName, distance_name AS distanceName,
                    ticket_number AS ticketNumber, qr_code AS qrCode, status,
                    issued_at AS issuedAt
             FROM tickets WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $ticketNumber = 'TKT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $this->insert('tickets', [
            'id' => $id,
            'registration_id' => (string) ($data['registrationId'] ?? ''),
            'user_id' => (string) ($data['userId'] ?? ''),
            'event_id' => (string) ($data['eventId'] ?? ''),
            'event_name' => (string) ($data['eventName'] ?? ''),
            'distance_name' => (string) ($data['distanceName'] ?? ''),
            'ticket_number' => $ticketNumber,
            'qr_code' => $data['qrCode'] ?? null,
            'status' => 'active',
        ]);
        return $this->find($id) ?? [];
    }
}
