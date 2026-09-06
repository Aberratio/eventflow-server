<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Results;

use ZmierzymyCzas\Domain\Repository;

final class ResultRepository extends Repository
{
    private const COLUMNS = 'id, event_id AS eventId, event_name AS eventName, distance_id AS distanceId, distance_name AS distanceName, user_id AS userId, registration_id AS registrationId, user_name AS userName, bib_number AS bibNumber, category, time, time_in_seconds AS timeInSeconds, net_time AS netTime, net_time_in_seconds AS netTimeInSeconds, position, category_position AS categoryPosition, status, gender, birth_year AS birthYear';

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['eventId' => 'event_id', 'distanceId' => 'distance_id', 'category' => 'category', 'gender' => 'gender'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (!empty($filters['search'])) {
            $where[] = '(user_name LIKE :search OR bib_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagination = $this->pagination($filters);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM results{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM results{$whereSql} ORDER BY position ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $pagination['page'], 'pageSize' => $pagination['pageSize']];
    }

    /** @return array<int, array<string, mixed>> */
    public function byEvent(string $eventId, ?string $distanceId = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM results WHERE event_id = :eventId';
        $params = ['eventId' => $eventId];
        if ($distanceId) {
            $sql .= ' AND distance_id = :distanceId';
            $params['distanceId'] = $distanceId;
        }
        $sql .= ' ORDER BY position ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function byUser(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM results WHERE user_id = :userId ORDER BY event_name');
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM results WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['splits'] = $this->splitsFor($id);
        return $row;
    }

    /**
     * Miedzyczasy (punkty kontrolne): opcjonalna lista par nazwa+czas -
     * spec 14 sekcja 2. Nie sa wymagane do publikacji podstawowego wyniku, wiec
     * zyja w osobnej tabeli zamiast kolumny na `results`.
     *
     * @return array<int, array{id: string, label: string, timeSeconds: float}>
     */
    public function splitsFor(string $resultId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, label, time_seconds AS timeSeconds FROM result_splits WHERE result_id = :resultId ORDER BY sort_order ASC');
        $stmt->execute(['resultId' => $resultId]);
        return array_map(static function (array $row): array {
            $row['timeSeconds'] = (float) $row['timeSeconds'];
            return $row;
        }, $stmt->fetchAll());
    }

    /** @param array<int, array{label: string, timeSeconds: float}> $splits */
    public function replaceSplits(string $resultId, array $splits): void
    {
        $this->pdo->prepare('DELETE FROM result_splits WHERE result_id = :resultId')->execute(['resultId' => $resultId]);
        foreach (array_values($splits) as $index => $split) {
            $this->insert('result_splits', [
                'id' => $this->id(),
                'result_id' => $resultId,
                'label' => (string) $split['label'],
                'time_seconds' => (float) $split['timeSeconds'],
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Spec 14 sekcja 1: DNF/DNS/DSQ sa statusami wyniku, wylaczonymi z
     * klasyfikacji miejsc - ResultImportRepository juz to wymusza dla importu
     * CSV, ale reczny wpis/edycja (POST/PATCH /results) szedl wprost przez
     * ta klase bez tej samej gwarancji. Egzekwujemy ja tutaj niezaleznie od
     * tego, co przyszlo w payloadzie (organizator moglby zostawic stare
     * miejsce przy zmianie statusu na DNS, np.).
     */
    private const NON_CLASSIFIED_STATUSES = ['dnf', 'dns', 'dsq'];

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $mapped = $this->map($id, $data);
        $status = (string) ($mapped['status'] ?? 'finished');
        if (in_array($status, self::NON_CLASSIFIED_STATUSES, true)) {
            $mapped['position'] = 0;
            $mapped['category_position'] = 0;
        }
        $this->insert('results', $mapped);
        if (array_key_exists('splits', $data) && is_array($data['splits'])) {
            $this->replaceSplits($id, $data['splits']);
        }
        $this->renumberEventDistance((string) $mapped['event_id'], (string) $mapped['distance_id']);
        return $this->find($id);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $current = $this->find($id);
        if ($current === null) {
            return null;
        }

        $mapped = $this->map($id, $data, false);
        unset($mapped['id']);
        $status = (string) ($data['status'] ?? $current['status']);
        if (in_array($status, self::NON_CLASSIFIED_STATUSES, true)) {
            $mapped['position'] = 0;
            $mapped['category_position'] = 0;
        }
        if ($mapped !== []) {
            $this->updateById('results', $id, $mapped);
        }
        if (array_key_exists('splits', $data) && is_array($data['splits'])) {
            $this->replaceSplits($id, $data['splits']);
        }
        $eventId = (string) ($data['eventId'] ?? $current['eventId']);
        $distanceId = (string) ($data['distanceId'] ?? $current['distanceId']);
        $this->renumberEventDistance($eventId, $distanceId);
        if ($eventId !== (string) $current['eventId'] || $distanceId !== (string) $current['distanceId']) {
            // A manual edit that moved the result to another event/distance
            // (unusual, but the payload technically allows it) leaves its old
            // siblings with a gap - renumber that group too.
            $this->renumberEventDistance((string) $current['eventId'], (string) $current['distanceId']);
        }
        return $this->find($id);
    }

    /**
     * Recompute position/categoryPosition for every `finished` result in an
     * event+distance, ranked by timeInSeconds ascending; category position is
     * counted the same way, per category. Runs after every manual
     * create/update so a new/changed result doesn't leave siblings with
     * stale ranks (e.g. a faster finisher inserted mid-table, or someone
     * flipped from `finished` to `dns`).
     *
     * ResultImportRepository::positions() (the reference algorithm for the
     * CSV-import commit path) additionally honors an explicit position taken
     * straight from the freshly-parsed import file for that one pass. That
     * signal doesn't carry over here: the `position` column already holds
     * this method's own previous output, so treating it as "explicit intent"
     * on a later call would let one earlier manual/organizer position lock
     * in and never get re-ranked by time again. Manual entry
     * (AddResultDialog.tsx) never lets an organizer set a position anyway -
     * ranking here is always computed, so pure time-ascending is the correct
     * rule for this path.
     */
    private function renumberEventDistance(string $eventId, string $distanceId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, category FROM results
             WHERE event_id = :eventId AND distance_id = :distanceId AND status = "finished"
             ORDER BY time_in_seconds ASC'
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
        $rows = $stmt->fetchAll();

        $categoryPositions = [];
        foreach ($rows as $index => $row) {
            $category = (string) $row['category'];
            $categoryPositions[$category] = ($categoryPositions[$category] ?? 0) + 1;
            $this->updateById('results', (string) $row['id'], [
                'position' => $index + 1,
                'category_position' => $categoryPositions[$category],
            ]);
        }
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM results WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function personalRecords(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT distance_name AS distance, MIN(time) AS time, event_name AS eventName, MAX(created_at) AS date FROM results WHERE user_id = :userId AND status = "finished" GROUP BY distance_name, event_name');
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function teamResults(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, event_id AS eventId, distance_id AS distanceId, distance_name AS distanceName, team_name AS teamName, members, total_time AS totalTime, total_time_in_seconds AS totalTimeInSeconds, position FROM team_results WHERE event_id = :eventId ORDER BY position ASC');
        $stmt->execute(['eventId' => $eventId]);
        return array_map(static function (array $row): array {
            $row['members'] = $row['members'] ? json_decode((string) $row['members'], true) : [];
            return $row;
        }, $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function documents(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, event_id AS eventId, name, type, distance_id AS distanceId, distance_name AS distanceName, file_url AS fileUrl, uploaded_at AS uploadedAt, uploaded_by AS uploadedBy, description FROM event_result_documents WHERE event_id = :eventId ORDER BY uploaded_at DESC');
        $stmt->execute(['eventId' => $eventId]);
        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function createDocument(string $eventId, array $data): array
    {
        $id = $this->id();
        $this->insert('event_result_documents', [
            'id' => $id,
            'event_id' => $eventId,
            'name' => $data['name'] ?? '',
            'type' => $data['type'] ?? 'open',
            'distance_id' => $data['distanceId'] ?? null,
            'distance_name' => $data['distanceName'] ?? null,
            'file_url' => $data['fileUrl'] ?? '',
            'uploaded_at' => date(DATE_ATOM),
            'uploaded_by' => $data['uploadedBy'] ?? '',
            'description' => $data['description'] ?? null,
            'sort_order' => 0,
        ]);
        foreach ($this->documents($eventId) as $document) {
            if ($document['id'] === $id) {
                return $document;
            }
        }
        return [];
    }

    /** @param array<string, mixed> $data */
    private function map(string $id, array $data, bool $creating = true): array
    {
        $map = [
            'eventId' => 'event_id',
            'eventName' => 'event_name',
            'distanceId' => 'distance_id',
            'distanceName' => 'distance_name',
            'userId' => 'user_id',
            'registrationId' => 'registration_id',
            'userName' => 'user_name',
            'bibNumber' => 'bib_number',
            'category' => 'category',
            'time' => 'time',
            'timeInSeconds' => 'time_in_seconds',
            'netTime' => 'net_time',
            'netTimeInSeconds' => 'net_time_in_seconds',
            'position' => 'position',
            'categoryPosition' => 'category_position',
            'status' => 'status',
            'gender' => 'gender',
            'birthYear' => 'birth_year',
        ];
        $out = $creating ? ['id' => $id] : [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $out[$column] = $data[$input];
            }
        }
        return $out;
    }
}
