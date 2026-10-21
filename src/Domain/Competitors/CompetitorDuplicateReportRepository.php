<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

use ZmierzymyCzas\Domain\Repository;

/**
 * R-08: zgłoszenia możliwych duplikatów Zawodnika od opiekunów / organizatorów.
 * Zgłoszenie nic nie scala - trafia do kolejki Moderatora/Admina.
 */
final class CompetitorDuplicateReportRepository extends Repository
{
    /**
     * Powtórne zgłoszenie tej samej pary przez to samo Konto, dopóki poprzednie jest
     * otwarte, zwraca istniejący wiersz (bez spamowania kolejki).
     *
     * @return array<string, mixed>
     */
    public function create(string $competitorId, string $duplicateOfId, string $reportedBy, string $reporterRole, string $note): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM competitor_duplicate_reports
             WHERE competitor_id = :c AND duplicate_of_id = :d AND reported_by = :u AND status = 'open' LIMIT 1"
        );
        $stmt->execute(['c' => $competitorId, 'd' => $duplicateOfId, 'u' => $reportedBy]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $id = $this->id();
            $this->insert('competitor_duplicate_reports', [
                'id' => $id,
                'competitor_id' => $competitorId,
                'duplicate_of_id' => $duplicateOfId,
                'reported_by' => $reportedBy,
                'reporter_role' => $reporterRole,
                'note' => mb_substr(trim($note), 0, 1000),
            ]);
        }

        return $this->list('all', (string) $id)[0];
    }

    /**
     * Kolejka dla Moderatora/Admina. Profile tylko jako imię/nazwisko/data urodzenia
     * (jak CompetitorDuplicatePresenter) - nigdy kod tożsamości ani dane kontaktowe.
     *
     * @param 'open'|'rejected'|'merged'|'all' $status
     * @return list<array<string, mixed>>
     */
    public function list(string $status = 'open', ?string $onlyId = null): array
    {
        $sql = "SELECT r.id, r.competitor_id, r.duplicate_of_id, r.reporter_role, r.note, r.status, r.reject_reason, r.created_at,
                       u.name AS reporter_name,
                       a.first_name AS a_first, a.last_name AS a_last, a.birth_date AS a_birth,
                       b.first_name AS b_first, b.last_name AS b_last, b.birth_date AS b_birth
                FROM competitor_duplicate_reports r
                JOIN competitors a ON a.id = r.competitor_id
                JOIN competitors b ON b.id = r.duplicate_of_id
                LEFT JOIN users u ON u.id = r.reported_by
                WHERE " . ($onlyId !== null ? 'r.id = :id' : ($status === 'all' ? '1 = 1' : 'r.status = :status')) . '
                ORDER BY r.created_at DESC, r.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($onlyId !== null ? ['id' => $onlyId] : ($status === 'all' ? [] : ['status' => $status]));

        return array_values(array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'competitorId' => (string) $r['competitor_id'],
            'duplicateOfId' => (string) $r['duplicate_of_id'],
            'competitor' => ['firstName' => (string) $r['a_first'], 'lastName' => (string) $r['a_last'], 'birthDate' => $r['a_birth'] !== null ? (string) $r['a_birth'] : null],
            'duplicateOf' => ['firstName' => (string) $r['b_first'], 'lastName' => (string) $r['b_last'], 'birthDate' => $r['b_birth'] !== null ? (string) $r['b_birth'] : null],
            'reporterName' => $r['reporter_name'] !== null ? (string) $r['reporter_name'] : null,
            'reporterRole' => (string) $r['reporter_role'],
            'note' => (string) $r['note'],
            'status' => (string) $r['status'],
            'rejectReason' => $r['reject_reason'] !== null ? (string) $r['reject_reason'] : null,
            'createdAt' => (string) $r['created_at'],
        ], $stmt->fetchAll()));
    }

    /**
     * Odrzucenie otwartego zgłoszenia. null = brak zgłoszenia; wyjątek = już rozstrzygnięte.
     *
     * @return array<string, mixed>|null
     */
    public function reject(string $id, string $resolvedBy, string $reason): ?array
    {
        if ($this->list('all', $id) === []) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE competitor_duplicate_reports
             SET status = 'rejected', reject_reason = :reason, resolved_by = :by, resolved_at = NOW()
             WHERE id = :id AND status = 'open'"
        );
        $stmt->execute(['reason' => mb_substr(trim($reason), 0, 500) ?: null, 'by' => $resolvedBy, 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new \DomainException('report_already_resolved');
        }

        return $this->list('all', $id)[0];
    }

    /**
     * Wywoływane w transakcji scalenia: otwarte zgłoszenia dotyczące właśnie scalonej pary
     * (w dowolnym kierunku) dostają status merged.
     */
    public function markMergedForPair(string $survivorId, string $absorbedId): void
    {
        $this->pdo->prepare(
            "UPDATE competitor_duplicate_reports SET status = 'merged', resolved_at = NOW()
             WHERE status = 'open'
               AND ((competitor_id = :a1 AND duplicate_of_id = :b1) OR (competitor_id = :b2 AND duplicate_of_id = :a2))"
        )->execute(['a1' => $survivorId, 'b1' => $absorbedId, 'a2' => $survivorId, 'b2' => $absorbedId]);
    }

    /**
     * Czy Zawodnik ma Zapis na którekolwiek z (niekasowanych) wydarzeń tego organizatora.
     */
    public function competitorRegisteredOnOrganizersEvent(string $competitorId, string $organizerId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM registrations r JOIN events e ON e.id = r.event_id
             WHERE r.competitor_id = :c AND e.organizer_id = :o AND e.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['c' => $competitorId, 'o' => $organizerId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * R-01: czy Zawodnik ma Zapis na niekasowanym wydarzeniu, którego aktywnym Współzarządcą jest Konto.
     */
    public function competitorRegisteredOnCoManagedEvent(string $competitorId, string $accountId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM registrations r JOIN events e ON e.id = r.event_id
             JOIN event_team_members m ON m.event_id = e.id AND m.user_id = :a AND m.status = 'active'
             WHERE r.competitor_id = :c AND e.deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute(['c' => $competitorId, 'a' => $accountId]);

        return $stmt->fetchColumn() !== false;
    }
}
