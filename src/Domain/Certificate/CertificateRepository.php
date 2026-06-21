<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Certificate;

use ZmierzymyCzas\Domain\Repository;

final class CertificateRepository extends Repository
{
    /** @return array<int, array<string, mixed>> */
    public function byUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, result_id AS resultId, user_id AS userId, event_id AS eventId,
                    event_name AS eventName, distance_name AS distanceName,
                    certificate_number AS certificateNumber, pdf_url AS pdfUrl,
                    finish_time AS finishTime, position, issued_at AS issuedAt
             FROM certificates WHERE user_id = :userId ORDER BY issued_at DESC'
        );
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, result_id AS resultId, user_id AS userId, event_id AS eventId,
                    event_name AS eventName, distance_name AS distanceName,
                    certificate_number AS certificateNumber, pdf_url AS pdfUrl,
                    finish_time AS finishTime, position, issued_at AS issuedAt
             FROM certificates WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $certNumber = 'CERT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $this->insert('certificates', [
            'id' => $id,
            'result_id' => (string) ($data['resultId'] ?? ''),
            'user_id' => (string) ($data['userId'] ?? ''),
            'event_id' => (string) ($data['eventId'] ?? ''),
            'event_name' => (string) ($data['eventName'] ?? ''),
            'distance_name' => (string) ($data['distanceName'] ?? ''),
            'certificate_number' => $certNumber,
            'pdf_url' => $data['pdfUrl'] ?? null,
            'finish_time' => $data['finishTime'] ?? null,
            'position' => isset($data['position']) ? (int) $data['position'] : null,
        ]);
        return $this->find($id) ?? [];
    }
}
