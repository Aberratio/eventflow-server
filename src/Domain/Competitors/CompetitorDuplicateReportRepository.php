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

        return $this->listOpen((string) $id)[0];
    }

    /**
     * Kolejka dla Moderatora/Admina. Profile tylko jako imię/nazwisko/data urodzenia
     * (jak CompetitorDuplicatePresenter) - nigdy kod tożsamości ani dane kontaktowe.
     *
     * @return list<array<string, mixed>>
     */
    public function listOpen(?string $onlyId = null): array
    {
        $sql = "SELECT r.id, r.competitor_id, r.duplicate_of_id, r.reporter_role, r.note, r.status, r.created_at,
                       u.name AS reporter_name,
                       a.first_name AS a_first, a.last_name AS a_last, a.birth_date AS a_birth,
                       b.first_name AS b_first, b.last_name AS b_last, b.birth_date AS b_birth
                FROM competitor_duplicate_reports r
                JOIN competitors a ON a.id = r.competitor_id
                JOIN competitors b ON b.id = r.duplicate_of_id
                LEFT JOIN users u ON u.id = r.reported_by
                WHERE " . ($onlyId === null ? "r.status = 'open'" : 'r.id = :id') . '
                ORDER BY r.created_at DESC, r.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($onlyId === null ? [] : ['id' => $onlyId]);

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
            'createdAt' => (string) $r['created_at'],
        ], $stmt->fetchAll()));
    }
}
