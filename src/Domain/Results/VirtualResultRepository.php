<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Results;

use ZmierzymyCzas\Domain\Errors\InvalidInput;
use ZmierzymyCzas\Domain\Repository;

final class VirtualResultRepository extends Repository
{
    private const COLUMNS = <<<'SQL'
id, event_id AS eventId, event_name AS eventName, distance_id AS distanceId,
distance_name AS distanceName, registration_id AS registrationId, user_id AS userId,
user_name AS userName, user_email AS userEmail, submitted_time AS submittedTime,
submitted_time_in_seconds AS submittedTimeInSeconds, proof_url AS proofUrl, notes,
submitted_at AS submittedAt, status, reviewed_at AS reviewedAt, reviewed_by AS reviewedBy,
rejection_reason AS rejectionReason
SQL;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['eventId' => 'event_id', 'distanceId' => 'distance_id', 'userId' => 'user_id', 'status' => 'status'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM virtual_result_submissions{$whereSql} ORDER BY submitted_at DESC, id DESC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM virtual_result_submissions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $data */
    public function submit(array $data, string $userId): array
    {
        $eventId = (string) ($data['eventId'] ?? '');
        $distanceId = (string) ($data['distanceId'] ?? '');
        $time = trim((string) ($data['submittedTime'] ?? ''));
        $seconds = (int) ($data['submittedTimeInSeconds'] ?? 0);

        if ($eventId === '' || $distanceId === '' || $time === '' || $seconds <= 0) {
            throw new InvalidInput('validation_error');
        }

        $this->pdo->beginTransaction();
        try {
            $event = $this->event($eventId);
            if (!$event || !(bool) $event['is_virtual']) {
                throw new InvalidInput('virtual_event_required');
            }
            if (!$this->isWindowOpen($event)) {
                throw new InvalidInput('virtual_result_window_closed');
            }

            $registration = $this->registration($eventId, $distanceId, $userId);
            if (!$registration) {
                throw new InvalidInput('virtual_result_registration_required');
            }

            if ($this->hasActiveSubmission($eventId, $distanceId, $userId)) {
                throw new InvalidInput('virtual_result_duplicate');
            }

            $id = $this->id();
            $this->insert('virtual_result_submissions', [
                'id' => $id,
                'event_id' => $eventId,
                'event_name' => $event['name'],
                'distance_id' => $distanceId,
                'distance_name' => $registration['distanceName'],
                'registration_id' => $registration['id'],
                'user_id' => $userId,
                'user_name' => $registration['userName'],
                'user_email' => $registration['userEmail'],
                'submitted_time' => $time,
                'submitted_time_in_seconds' => $seconds,
                'proof_url' => $this->nullableString($data['proofUrl'] ?? null, 512),
                'notes' => $this->nullableString($data['notes'] ?? null, 5000),
                'submitted_at' => date(DATE_ATOM),
                'status' => 'pending',
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id) ?? [];
    }

    public function review(string $id, string $status, string $reviewedBy, ?string $reason = null): ?array
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidInput('validation_error');
        }

        $this->pdo->beginTransaction();
        try {
            $submission = $this->find($id);
            if (!$submission) {
                $this->pdo->rollBack();
                return null;
            }

            $this->updateById('virtual_result_submissions', $id, [
                'status' => $status,
                'reviewed_at' => date(DATE_ATOM),
                'reviewed_by' => $reviewedBy,
                'rejection_reason' => $status === 'rejected' ? $this->nullableString($reason, 5000) : null,
            ]);

            if ($status === 'approved') {
                $this->upsertOfficialResult($submission);
            } else {
                $this->deleteOfficialResult($submission);
            }
            $this->recalculatePositions((string) $submission['eventId'], (string) $submission['distanceId']);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /** @return array<string, mixed>|null */
    private function event(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, is_virtual, start_date, end_date FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    private function registration(string $eventId, string $distanceId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT r.id, r.event_id AS eventId, r.event_name AS eventName, r.user_id AS userId,
r.user_name AS userName, r.user_email AS userEmail, r.distance_id AS distanceId,
r.distance_name AS distanceName, r.category, r.bib_number AS bibNumber,
u.gender, u.birth_year AS birthYear
FROM registrations r
LEFT JOIN users u ON u.id = r.user_id
WHERE r.event_id = :eventId
  AND r.distance_id = :distanceId
  AND r.user_id = :userId
  AND r.status NOT IN ('cancelled', 'removed', 'expired')
ORDER BY r.registered_at DESC
LIMIT 1
SQL);
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId, 'userId' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $event */
    private function isWindowOpen(array $event): bool
    {
        $today = date('Y-m-d');
        $start = substr((string) ($event['start_date'] ?? ''), 0, 10);
        $end = substr((string) ($event['end_date'] ?? ''), 0, 10);
        return $start !== '' && $end !== '' && $start <= $today && $today <= $end;
    }

    private function hasActiveSubmission(string $eventId, string $distanceId, string $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM virtual_result_submissions WHERE event_id = :eventId AND distance_id = :distanceId AND user_id = :userId AND status IN ('pending', 'approved')");
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId, 'userId' => $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $submission */
    private function upsertOfficialResult(array $submission): void
    {
        $registration = $this->registration(
            (string) $submission['eventId'],
            (string) $submission['distanceId'],
            (string) $submission['userId']
        );
        if (!$registration) {
            throw new InvalidInput('virtual_result_registration_required');
        }

        $this->deleteOfficialResult($submission);
        $this->insert('results', [
            'id' => $this->id(),
            'event_id' => $submission['eventId'],
            'event_name' => $submission['eventName'],
            'distance_id' => $submission['distanceId'],
            'distance_name' => $submission['distanceName'],
            'user_id' => $submission['userId'],
            'registration_id' => $submission['registrationId'],
            'user_name' => $submission['userName'],
            'bib_number' => (string) ($registration['bibNumber'] ?? ''),
            'category' => (string) ($registration['category'] ?? 'Open'),
            'time' => $submission['submittedTime'],
            'time_in_seconds' => (int) $submission['submittedTimeInSeconds'],
            'position' => 0,
            'category_position' => 0,
            'status' => 'finished',
            // Only results from a live start count towards records - a virtual result
            // is excluded, even after organizer verification. This row is the
            // materialization of an approved virtual submission (not a live
            // timing), so it must not take part in ResultRepository::lifetimeRecordsForCompetitor().
            'is_virtual' => 1,
            'gender' => $registration['gender'] ?? null,
            'birth_year' => $registration['birthYear'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $submission */
    private function deleteOfficialResult(array $submission): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM results WHERE event_id = :eventId AND distance_id = :distanceId AND user_id = :userId');
        $stmt->execute([
            'eventId' => $submission['eventId'],
            'distanceId' => $submission['distanceId'],
            'userId' => $submission['userId'],
        ]);
    }

    private function recalculatePositions(string $eventId, string $distanceId): void
    {
        $stmt = $this->pdo->prepare("SELECT id, category FROM results WHERE event_id = :eventId AND distance_id = :distanceId AND status = 'finished' ORDER BY time_in_seconds ASC, id ASC");
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
        $categoryPositions = [];
        $position = 1;
        foreach ($stmt->fetchAll() as $result) {
            $category = (string) ($result['category'] ?? '');
            $categoryPositions[$category] = ($categoryPositions[$category] ?? 0) + 1;
            $this->updateById('results', (string) $result['id'], [
                'position' => $position,
                'category_position' => $categoryPositions[$category],
            ]);
            $position++;
        }
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
}
