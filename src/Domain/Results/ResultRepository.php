<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Results;

use ZmierzymyCzas\Domain\Competitors\CompetitorNamePresenter;
use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Support\InputRules;
use ZmierzymyCzas\Support\InputValidationException;

final class ResultRepository extends Repository
{
    private const COLUMNS = 'id, event_id AS eventId, event_name AS eventName, distance_id AS distanceId, distance_name AS distanceName, user_id AS userId, registration_id AS registrationId, user_name AS userName, bib_number AS bibNumber, category, time, time_in_seconds AS timeInSeconds, net_time AS netTime, net_time_in_seconds AS netTimeInSeconds, position, category_position AS categoryPosition, gender_position AS genderPosition, status, is_virtual AS isVirtual, publication_status AS publicationStatus, gender, birth_year AS birthYear';

    /**
     * Same column list as COLUMNS above, `r.`-qualified for byEvent()'s
     * anonymization join below (TD.6) — needed once the query also touches
     * `registrations`/`competitors`, both of which have their own `id`
     * column that would otherwise collide with `results.id`.
     */
    private const COLUMNS_R = 'r.id, r.event_id AS eventId, r.event_name AS eventName, r.distance_id AS distanceId, r.distance_name AS distanceName, r.user_id AS userId, r.registration_id AS registrationId, r.user_name AS userName, r.bib_number AS bibNumber, r.category, r.time, r.time_in_seconds AS timeInSeconds, r.net_time AS netTime, r.net_time_in_seconds AS netTimeInSeconds, r.position, r.category_position AS categoryPosition, r.gender_position AS genderPosition, r.status, r.is_virtual AS isVirtual, r.publication_status AS publicationStatus, r.gender, r.birth_year AS birthYear';

    /**
     * Shared FROM/JOIN shape behind byEvent()'s anonymization masking (TD.6),
     * now also reused by list() and byUser() below (TD.6 follow-up) - both
     * are equally public/unauthenticated `GET` endpoints (routes.php has no
     * AuthMiddleware on any of the three) reading the exact same `results`
     * table, so they share the exact same real-name leak byEvent() was fixed
     * for. Extracted here instead of copy-pasted so the identity-resolution
     * SQL (registration_id -> registrations.competitor_id authoritative,
     * user_id -> competitors.user_id fallback - see byEvent()'s own docblock
     * for the full reasoning) exists in exactly one place.
     */
    private static function anonymizationJoinSql(): string
    {
        return 'SELECT ' . self::COLUMNS_R . ',
                COALESCE(comp_reg.is_anonymized, comp_self.is_anonymized, 0) AS isAnonymizedFlag
            FROM results r
            LEFT JOIN registrations reg ON reg.id = r.registration_id
            LEFT JOIN competitors comp_reg ON comp_reg.id = reg.competitor_id
            LEFT JOIN competitors comp_self ON comp_self.user_id = r.user_id';
    }

    /**
     * Applies CompetitorNamePresenter::publicName() to every row fetched via
     * anonymizationJoinSql() above, mirroring byEvent()'s original
     * PHP-side masking so list()/byUser() behave identically.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function maskAnonymizedRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $isAnonymized = (bool) $row['isAnonymizedFlag'];
            unset($row['isAnonymizedFlag']);
            $row['userName'] = CompetitorNamePresenter::publicName((string) $row['userName'], $isAnonymized);
            return $row;
        }, $rows);
    }

    /**
     * TD.6 follow-up: `GET /results` is public/unauthenticated (routes.php)
     * just like `GET /events/{eventId}/results` - it must not leak an
     * anonymized competitor's real name either. See
     * anonymizationJoinSql()/maskAnonymizedRows() above for the shared
     * identity-resolution/masking logic byEvent() introduced this fix with.
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        // PP.76: only scalar query params count; an array value (?search[]=x) is ignored.
        $scalar = static fn (mixed $value): bool => (is_string($value) || is_int($value)) && !empty($value);
        foreach (['eventId' => 'event_id', 'distanceId' => 'distance_id', 'category' => 'category', 'gender' => 'gender'] as $input => $column) {
            if ($scalar($filters[$input] ?? null)) {
                $where[] = "r.{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if ($scalar($filters['search'] ?? null)) {
            // PP.76: native prepares (ATTR_EMULATE_PREPARES=false) reject a repeated named placeholder (HY093).
            $where[] = '(r.user_name LIKE :searchName OR r.bib_number LIKE :searchBib)';
            $params['searchName'] = $params['searchBib'] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagination = $this->pagination($filters);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM results r{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(self::anonymizationJoinSql() . "{$whereSql} ORDER BY r.position ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => self::maskAnonymizedRows($stmt->fetchAll()), 'total' => $total, 'page' => $pagination['page'], 'pageSize' => $pagination['pageSize']];
    }

    /**
     * TD.6 (E6.6 follow-up): public individual classification — `GET
     * /events/{eventId}/results` is unauthenticated (routes.php's own
     * comment on this endpoint), so a competitor's `is_anonymized` flag must
     * be respected here the same way E6.6 already wired it into the
     * Wyzwanie/Series ranking. `results.user_name` is a flat snapshot with no
     * FK to `competitors` on its own — the join below resolves identity the
     * same way lifetimeRecordsForCompetitor() already documents as this
     * class's established convention: `results.registration_id` ->
     * `registrations.competitor_id` is authoritative (migration 027,
     * "Zawodnik jako zrodlo tozsamosci zapisu"); a result with no
     * registration_id (older/manual entry, e.g. AddResultDialog.tsx's manual
     * payload) falls back to `results.user_id` = `competitors.user_id`.
     * COALESCE prefers the registration-linked competitor when both would
     * somehow resolve (they should always agree when both exist).
     *
     * @return array<int, array<string, mixed>>
     */
    public function byEvent(string $eventId, ?string $distanceId = null): array
    {
        $sql = self::anonymizationJoinSql() . ' WHERE r.event_id = :eventId';
        $params = ['eventId' => $eventId];
        if ($distanceId) {
            $sql .= ' AND r.distance_id = :distanceId';
            $params['distanceId'] = $distanceId;
        }
        $sql .= ' ORDER BY r.position ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return self::maskAnonymizedRows($stmt->fetchAll());
    }

    /**
     * TD.6 follow-up: `GET /users/{userId}/results` is public/unauthenticated
     * (routes.php) just like byEvent() above - same leak, same fix. See
     * anonymizationJoinSql()/maskAnonymizedRows() for the shared logic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(self::anonymizationJoinSql() . ' WHERE r.user_id = :userId ORDER BY r.event_name');
        $stmt->execute(['userId' => $userId]);
        return self::maskAnonymizedRows($stmt->fetchAll());
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

    /**
     * PP.76: request-body rules for POST/PATCH /results (see InputRules). eventName/distanceName
     * are deliberately lenient here: create() derives them from the event when absent.
     */
    private const INPUT_RULES = [
        'eventId' => [InputRules::TEXT_REQUIRED, 'Wydarzenie'],
        'distanceId' => [InputRules::TEXT_REQUIRED, 'Dystans'],
        'eventName' => [InputRules::TEXT, 'Nazwa wydarzenia'],
        'distanceName' => [InputRules::TEXT, 'Nazwa dystansu'],
        'userName' => [InputRules::TEXT_REQUIRED, 'Imię i nazwisko zawodnika'],
        'userId' => [InputRules::TEXT, 'Konto zawodnika'],
        'registrationId' => [InputRules::TEXT_NULLABLE, 'Zapis'],
        'bibNumber' => [InputRules::TEXT, 'Numer startowy'],
        'category' => [InputRules::TEXT, 'Kategoria'],
        'status' => [InputRules::ENUM, 'Status wyniku', ['finished', 'dnf', 'dns', 'dsq']],
        'time' => [InputRules::TEXT, 'Czas'],
        'timeInSeconds' => [InputRules::NUMBER, 'Czas w sekundach'],
        'netTime' => [InputRules::TEXT_NULLABLE, 'Czas netto'],
        'netTimeInSeconds' => [InputRules::NUMBER_NULLABLE, 'Czas netto w sekundach'],
        'position' => [InputRules::INT_NULLABLE, 'Miejsce'],
        'categoryPosition' => [InputRules::INT_NULLABLE, 'Miejsce w kategorii'],
        'gender' => [InputRules::ENUM_NULLABLE, 'Płeć', ['M', 'K']],
        'birthYear' => [InputRules::INT_NULLABLE, 'Rok urodzenia'],
        'splits' => [InputRules::OBJECTS_NULLABLE, 'Międzyczasy'],
    ];

    /**
     * PP.76: every split needs a label and a non-negative time (both NOT NULL in result_splits).
     *
     * @param array<string, mixed> $data
     */
    private function assertSplits(array $data): void
    {
        if (!isset($data['splits']) || !is_array($data['splits'])) {
            return;
        }
        foreach ($data['splits'] as $index => $split) {
            $rules = [
                'label' => [InputRules::TEXT_REQUIRED, 'Nazwa międzyczasu'],
                'timeSeconds' => [InputRules::NUMBER, 'Czas międzyczasu'],
            ];
            try {
                InputRules::check($split, $rules, true);
                if (!array_key_exists('timeSeconds', $split)) {
                    throw InputRules::required('timeSeconds', $rules['timeSeconds'][1]);
                }
            } catch (InputValidationException $e) {
                throw new InputValidationException($e->getMessage(), 'splits[' . $index . '].' . $e->field);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        InputRules::check($data, self::INPUT_RULES, true);
        // Places are NOT NULL columns; null means "not given" (renumbering computes them).
        foreach (['position', 'categoryPosition'] as $placeField) {
            if (array_key_exists($placeField, $data) && $data[$placeField] === null) {
                unset($data[$placeField]);
            }
        }
        $this->assertSplits($data);
        $status = (string) ($data['status'] ?? 'finished');
        if ($status === 'finished') {
            if (!array_key_exists('time', $data) || trim((string) $data['time']) === '') {
                throw InputRules::required('time', 'Czas');
            }
            if (!array_key_exists('timeInSeconds', $data)) {
                throw InputRules::required('timeInSeconds', 'Czas w sekundach');
            }
        }
        $data = $this->withDerivedNames($data);
        // Defaults for NOT NULL columns the caller left out (what non-strict MariaDB stored
        // silently): only reachable for manual results, imports always send all of them.
        $data += [
            'userId' => '',
            'bibNumber' => '',
            'category' => '',
            'time' => strtoupper($status),
            'timeInSeconds' => 0,
            'position' => 0,
            'categoryPosition' => 0,
        ];
        $id = $this->id();
        $mapped = $this->map($id, $data);
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

    /**
     * PP.76: eventName/distanceName are NOT NULL snapshots; when the caller omits them they come
     * from the event and distance (server-authoritative). Unknown ids are a validation error.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withDerivedNames(array $data): array
    {
        $eventId = (string) $data['eventId'];
        $distanceId = (string) $data['distanceId'];
        $needsEvent = !isset($data['eventName']) || trim((string) $data['eventName']) === '';
        $needsDistance = !isset($data['distanceName']) || trim((string) $data['distanceName']) === '';

        if ($needsEvent) {
            $stmt = $this->pdo->prepare('SELECT name FROM events WHERE id = :eventId');
            $stmt->execute(['eventId' => $eventId]);
            $name = $stmt->fetchColumn();
            if ($name === false) {
                throw InputRules::invalid('eventId', 'Wydarzenie');
            }
            $data['eventName'] = (string) $name;
        }
        if ($needsDistance) {
            $stmt = $this->pdo->prepare('SELECT name FROM event_distances WHERE id = :distanceId AND event_id = :eventId');
            $stmt->execute(['distanceId' => $distanceId, 'eventId' => $eventId]);
            $name = $stmt->fetchColumn();
            if ($name === false) {
                throw InputRules::invalid('distanceId', 'Dystans');
            }
            $data['distanceName'] = (string) $name;
        }

        return $data;
    }

    /** Fields whose change counts as a correction (spec 14 pkt 6) and is kept in the trail. */
    private const CORRECTION_FIELDS = ['time', 'netTime', 'status', 'position', 'categoryPosition'];

    /**
     * G-03 (spec 14 pkt 6): stores who/when/what for one correction of a result - the previous and
     * new value of every tracked field that actually changed. Nothing is stored when none did.
     *
     * @param array<string, mixed> $before the result before the update
     * @param array<string, mixed> $after the result after the update (positions already renumbered)
     * @return bool whether a trail row was written
     */
    public function recordCorrection(array $before, array $after, string $userId, ?string $reason): bool
    {
        $changes = [];
        foreach (self::CORRECTION_FIELDS as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if (in_array($field, ['position', 'categoryPosition'], true)) {
                $from = $from === null ? null : (int) $from;
                $to = $to === null ? null : (int) $to;
            }
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }
        if ($changes === []) {
            return false;
        }
        $reason = $reason !== null ? trim($reason) : null;
        $this->insert('result_corrections', [
            'id' => $this->id(),
            'result_id' => (string) $before['id'],
            'event_id' => (string) $before['eventId'],
            'corrected_by' => $userId,
            'corrected_at' => date(DATE_ATOM),
            'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null,
            'was_official' => ($before['publicationStatus'] ?? '') === 'official' ? 1 : 0,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return true;
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function corrections(string $resultId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.result_id AS resultId, c.corrected_by AS correctedBy, u.name AS correctedByName,
                    c.corrected_at AS correctedAt, c.reason, c.was_official AS wasOfficial, c.changes
             FROM result_corrections c LEFT JOIN users u ON u.id = c.corrected_by
             WHERE c.result_id = :id ORDER BY c.corrected_at DESC, c.id DESC'
        );
        $stmt->execute(['id' => $resultId]);

        return array_map(static function (array $row): array {
            $row['wasOfficial'] = (bool) $row['wasOfficial'];
            $row['changes'] = json_decode((string) $row['changes'], true) ?? [];
            return $row;
        }, $stmt->fetchAll());
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $current = $this->find($id);
        if ($current === null) {
            return null;
        }

        InputRules::check($data, self::INPUT_RULES, false);
        // G-03: a place is a positive integer (manual override, flagged so renumbering keeps it) or null
        // (releases the override - the computed ranking takes over again).
        $manualPlaces = [];
        foreach (['position' => ['position', 'Miejsce'], 'categoryPosition' => ['category_position', 'Miejsce w kategorii']] as $field => [$column, $label]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] !== null) {
                if ((int) $data[$field] < 1) {
                    throw InputRules::invalid($field, $label);
                }
            }
            $manualPlaces[$column] = $data[$field] === null ? null : (int) $data[$field];
            unset($data[$field]);
        }
        $this->assertManualPlacesFree($id, $manualPlaces, (string) ($data['eventId'] ?? $current['eventId']), (string) ($data['distanceId'] ?? $current['distanceId']), (string) ($data['category'] ?? $current['category']), (string) ($data['status'] ?? $current['status']));
        $this->assertSplits($data);
        $mapped = $this->map($id, $data, false);
        unset($mapped['id']);
        $status = (string) ($data['status'] ?? $current['status']);
        if (in_array($status, self::NON_CLASSIFIED_STATUSES, true)) {
            $mapped['position'] = 0;
            $mapped['category_position'] = 0;
            $mapped['gender_position'] = 0;
            $mapped['position_manual'] = 0;
            $mapped['category_position_manual'] = 0;
        } else {
            foreach ($manualPlaces as $column => $place) {
                $mapped[$column . '_manual'] = $place === null ? 0 : 1;
                if ($place !== null) {
                    $mapped[$column] = $place;
                }
            }
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
            'SELECT id, category, position_manual AS positionManual, category_position AS categoryPosition,
                    position, category_position_manual AS categoryManual FROM results
             WHERE event_id = :eventId AND distance_id = :distanceId AND status = "finished"
             ORDER BY time_in_seconds ASC, id ASC'
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
        $rows = $stmt->fetchAll();

        // G-03: a manually corrected place is left as the organizer set it, and the automatic places
        // of the others skip the numbers taken by a manual one - so a manual place never coincides
        // with a computed one (two manual places cannot coincide either, see assertManualPlacesFree()).
        $takenOpen = [];
        $takenCategory = [];
        foreach ($rows as $row) {
            if ((int) $row['positionManual']) {
                $takenOpen[(int) $row['position']] = true;
            }
            if ((int) $row['categoryManual']) {
                $takenCategory[(string) $row['category']][(int) $row['categoryPosition']] = true;
            }
        }

        $nextOpen = 0;
        $nextCategory = [];
        foreach ($rows as $row) {
            $category = (string) $row['category'];
            $places = [];
            if (!(int) $row['positionManual']) {
                do {
                    ++$nextOpen;
                } while (isset($takenOpen[$nextOpen]));
                $places['position'] = $nextOpen;
            }
            if (!(int) $row['categoryManual']) {
                $next = $nextCategory[$category] ?? 0;
                do {
                    ++$next;
                } while (isset($takenCategory[$category][$next]));
                $nextCategory[$category] = $next;
                $places['category_position'] = $next;
            }
            if ($places !== []) {
                $this->updateById('results', (string) $row['id'], $places);
            }
        }
        self::recomputeGenderPositions($this->pdo, $eventId, $distanceId);
    }

    /**
     * G-03: "miejsce wg płci" - rank by time among the finishers of the same gender in the event+distance
     * (0 for a result without a known gender or one that did not finish). Never manual.
     */
    public static function recomputeGenderPositions(\PDO $pdo, string $eventId, string $distanceId): void
    {
        $pdo->prepare("UPDATE results SET gender_position = 0 WHERE event_id = :e AND distance_id = :d AND gender_position <> 0 AND (status <> 'finished' OR gender IS NULL OR gender NOT IN ('M', 'K'))")
            ->execute(['e' => $eventId, 'd' => $distanceId]);
        $stmt = $pdo->prepare("SELECT id, gender, gender_position AS genderPosition FROM results WHERE event_id = :e AND distance_id = :d AND status = 'finished' AND gender IN ('M', 'K') ORDER BY time_in_seconds ASC, id ASC");
        $stmt->execute(['e' => $eventId, 'd' => $distanceId]);
        $counters = [];
        $update = $pdo->prepare('UPDATE results SET gender_position = :p WHERE id = :id');
        foreach ($stmt->fetchAll() as $row) {
            $gender = (string) $row['gender'];
            $counters[$gender] = ($counters[$gender] ?? 0) + 1;
            if ((int) $row['genderPosition'] !== $counters[$gender]) {
                $update->execute(['p' => $counters[$gender], 'id' => $row['id']]);
            }
        }
    }

    /**
     * G-03: a manually set place must be free in its classification - no other result of the event+distance
     * (and, for the category place, of the same category) may already hold that number as a manual place.
     * The automatic places move around manual ones, so they never collide.
     *
     * @param array<string, ?int> $manualPlaces column => place (null releases the override)
     */
    private function assertManualPlacesFree(string $id, array $manualPlaces, string $eventId, string $distanceId, string $category, string $status): void
    {
        if (in_array($status, self::NON_CLASSIFIED_STATUSES, true)) {
            return;
        }
        $checks = [
            'position' => ['position_manual', 'position', 'position', 'Miejsce', ''],
            'category_position' => ['category_position_manual', 'category_position', 'categoryPosition', 'Miejsce w kategorii', ' AND category = :category'],
        ];
        foreach ($checks as $column => [$flag, $placeColumn, $field, $label, $extra]) {
            $place = $manualPlaces[$column] ?? null;
            if ($place === null) {
                continue;
            }
            $params = ['e' => $eventId, 'd' => $distanceId, 'id' => $id, 'p' => $place];
            if ($extra !== '') {
                $params['category'] = $category;
            }
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM results WHERE event_id = :e AND distance_id = :d AND status = 'finished' AND id <> :id AND {$flag} = 1 AND {$placeColumn} = :p{$extra}");
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new InputValidationException(
                    $label . ' ' . $place . ' jest już ustawione ręcznie dla innego zawodnika w tej klasyfikacji.',
                    $field,
                    'result_place_taken'
                );
            }
        }
    }

    /**
     * E2.3 (spec 14 sekcja 6): "Organizator publikuje wyniki wstepne od razu
     * po imporcie/recznym wpisaniu ... Po weryfikacji ... organizator
     * oznacza wyniki jako oficjalne."
     *
     * Granulacja: per event+distance (klasyfikacja), NIE per pojedynczy
     * Result ani per cale Wydarzenie. Rozumowanie: `renumberEventDistance()`
     * powyzej i `ResultImportRepository::positions()` juz traktuja
     * event+distance jako jednostke, w ktorej wyniki tworza jedna spojna
     * tabele klasyfikacji - organizator zatwierdza "wyniki 10 km", nie
     * pojedynczego zawodnika (za drobnoziarniste - reklamacje dotycza
     * pojedynczych wpisow, ale publikacja to decyzja o calej tabeli) ani cale
     * Wydarzenie (za grube - wydarzenie z kilkoma dystansami może miec
     * niezalezne tempo weryfikacji reklamacji na mecie dla kazdego z nich,
     * np. 5 km gotowe do ogloszenia oficjalnych zanim komisja skonczy
     * sprawdzac 10 km).
     *
     * Ta "oficjalnosc" nie jest osobna tabela event+distance -> status, tylko
     * kolumna na kazdym wierszu `results` (ten sam wzorzec denormalizacji co
     * juz obecne na tej tabeli distance_id/distance_name) - unika JOINa przy
     * kazdym odczycie (GET /results, /events/{id}/results) kosztem
     * masowego UPDATE tutaj, ktory i tak jest rzadka, recznie wywolywana
     * akcja organizatora.
     *
     * Korekta pojedynczego wyniku (PATCH /results/{id} -> update() powyzej)
     * celowo NIE dotyka tej kolumny - `map()` jej nie zna, wiec
     * publication_status przetrwa kazda korekte bez zmian, zgodnie ze
     * specyfikacja: "Korekta jest mozliwa zawsze, takze po ogloszeniu
     * oficjalnych" - oficjalnosc nie jest cofana ani odzyskiwana przy kazdej
     * zmianie, tylko trwa, dopoki organizator jej jawnie nie zmieni.
     *
     * @return int liczba zaktualizowanych wierszy (0, gdy event+distance nie ma jeszcze zadnych wynikow)
     */
    public function markOfficial(string $eventId, string $distanceId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE results SET publication_status = "official" WHERE event_id = :eventId AND distance_id = :distanceId'
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
        return $stmt->rowCount();
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

    /**
     * E2.4 (spec 14 sekcja 3): "Rekord zyciowy liczy sie per etykieta/dlugosc
     * dystansu ... niezaleznie od tego, na ktorym Wydarzeniu czy w ktorej
     * edycji padl - nie per Wydarzenie. Tylko wyniki ze startu na zywo bija
     * rekord."
     *
     * Grouping key: distance LENGTH (normalized to meters), not
     * distance_id/event_id (each Event creates its own event_distances row
     * even for objectively the same distance) and not distance_name alone
     * (two Events can label the same length differently, e.g. "Polmaraton"
     * vs "21 km"). event_distances.length was made nullable by migration 014
     * (some legacy/incomplete distances never got a length) - those fall
     * back to grouping by name, a documented best-effort seam rather than
     * silently dropping them from the response.
     *
     * Qualifying rows: status = 'finished' (excludes dnf/dns/dsq - not
     * "actually completed") AND is_virtual = 0 (excludes approved virtual
     * submissions materialized by VirtualResultRepository::upsertOfficialResult()
     * - spec: a virtual result "nie ma tej samej wiarygodnosci" even after
     * organizer verification, so it never counts toward a record).
     *
     * Ranking metric: GROSS time_in_seconds, not net. This mirrors the
     * existing ranking convention already established elsewhere in this
     * domain - renumberEventDistance() and ResultImportRepository::positions()
     * both rank/order finishers by gross time_in_seconds, never net (net is
     * optional - "nie kazdy pomiar ma mate startowa" - so it can't be the
     * universal comparison key). A record is kept consistent with how a
     * result is ranked everywhere else in this codebase.
     *
     * Live query, not materialized: a correction to a result's time/status
     * (PATCH /results/{id}) or a fresh import is picked up on the very next
     * call with no extra invalidation step - there is no stale/wrong record
     * to leave standing (acceptance criterion 5). This is a read-heavy, not
     * write-heavy, path (unlike event_distances.registered_count from E1.3,
     * which is a per-write counter maintained precisely because it's read on
     * every hot list view under write contention) - a live query is simpler
     * and there's no established performance reason here to materialize it.
     *
     * Competitor identity: results.registration_id (E2.1) links to
     * registrations.competitor_id when present - the authoritative source
     * (migration 027: "Zawodnik jako zrodlo tozsamosci zapisu"). Falls back
     * to results.user_id = competitors.user_id for older/manual results that
     * never got a registration_id (a Zawodnik's own Konto, not an Opiekun's -
     * see AddResultDialog.tsx's manual-entry payload, which sends userId but
     * not registrationId).
     *
     * @return array<int, array{distanceLabel: string, distanceLengthMeters: ?float, resultId: string, eventId: string, eventName: string, distanceId: string, time: string, timeInSeconds: float, netTime: ?string, netTimeInSeconds: ?float, achievedAt: ?string}>
     */
    public function lifetimeRecordsForCompetitor(string $competitorId): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT r.id AS resultId, r.event_id AS eventId, r.event_name AS eventName,
       r.distance_id AS distanceId, r.distance_name AS distanceName,
       r.time, r.time_in_seconds AS timeInSeconds, r.net_time AS netTime,
       r.net_time_in_seconds AS netTimeInSeconds, r.created_at AS achievedAt,
       ed.length AS distanceLength, ed.unit AS distanceUnit
FROM results r
LEFT JOIN registrations reg ON reg.id = r.registration_id
LEFT JOIN competitors comp_self ON comp_self.user_id = r.user_id
LEFT JOIN event_distances ed ON ed.id = r.distance_id
WHERE r.status = 'finished'
  AND r.is_virtual = 0
  AND COALESCE(reg.competitor_id, comp_self.id) = :competitorId
ORDER BY r.time_in_seconds ASC
SQL);
        $stmt->execute(['competitorId' => $competitorId]);

        // ORDER BY time_in_seconds ASC above means the first row seen for a
        // given grouping key is already the best (lowest) qualifying time -
        // subsequent rows for the same key are simply ignored.
        $best = [];
        foreach ($stmt->fetchAll() as $row) {
            $lengthMeters = $this->distanceLengthInMeters($row['distanceLength'], $row['distanceUnit']);
            $key = $lengthMeters !== null
                ? 'len:' . (string) round($lengthMeters)
                : 'name:' . mb_strtolower(trim((string) $row['distanceName']), 'UTF-8');

            if (isset($best[$key])) {
                continue;
            }

            $best[$key] = [
                'distanceLabel' => (string) $row['distanceName'],
                'distanceLengthMeters' => $lengthMeters,
                'resultId' => (string) $row['resultId'],
                'eventId' => (string) $row['eventId'],
                'eventName' => (string) $row['eventName'],
                'distanceId' => (string) $row['distanceId'],
                'time' => (string) $row['time'],
                'timeInSeconds' => (float) $row['timeInSeconds'],
                'netTime' => $row['netTime'] !== null ? (string) $row['netTime'] : null,
                'netTimeInSeconds' => $row['netTimeInSeconds'] !== null ? (float) $row['netTimeInSeconds'] : null,
                'achievedAt' => $row['achievedAt'] !== null ? (string) $row['achievedAt'] : null,
            ];
        }

        $records = array_values($best);
        usort($records, static function (array $a, array $b): int {
            if ($a['distanceLengthMeters'] === null && $b['distanceLengthMeters'] === null) {
                return $a['distanceLabel'] <=> $b['distanceLabel'];
            }
            if ($a['distanceLengthMeters'] === null) {
                return 1;
            }
            if ($b['distanceLengthMeters'] === null) {
                return -1;
            }
            return $a['distanceLengthMeters'] <=> $b['distanceLengthMeters'];
        });

        return $records;
    }

    private function distanceLengthInMeters(mixed $length, ?string $unit): ?float
    {
        if ($length === null) {
            return null;
        }
        $value = (float) $length;
        return $unit === 'm' ? $value : $value * 1000;
    }

    /**
     * LEGACY, superseded by teamClassification() below (E2.7) - kept only so
     * old fixture/seeder data (EventFixtureImporter::importTeamResults(),
     * database/seeders/001_demo.sql) still round-trips, and so nothing that
     * still points at `team_results` breaks. Do NOT build new features on
     * this: `team_results` stores a team result as a manually-entered,
     * standalone row (see TeamResultsManager.tsx's "Dodaj drużynę" dialog on
     * the client, and EventFixtureImporter::importTeamResults()) - exactly
     * the model spec 14 sekcja 4 rejects ("Wynik drużyny/sztafety jest
     * liczony z wyników indywidualnych członków, nie wpisywany osobno").
     * TeamResultsManager.tsx's add/delete handlers were never actually wired
     * to a write endpoint either (ResultsTab.tsx's handleAddTeamResult/
     * handleDeleteTeamResult only touch local React state - see git history),
     * so nothing outside fixtures ever persisted a row here through normal
     * use; this GET was the only route ever exposed for it.
     *
     * TD.6 KNOWN GAP (checked, not closed): `members` is an opaque JSON blob
     * with no FK to `competitors`/`users` at all (see the CREATE TABLE in
     * migration 001 and EventFixtureImporter::importTeamResults() — it writes
     * whatever the fixture's `members` array already contains, verbatim).
     * There is no identity chain here to resolve an is_anonymized flag
     * against, unlike byEvent()'s registration_id/user_id chain above — if a
     * member name were ever anonymization-sensitive, this table has no way to
     * know which competitor it belongs to. Left unmasked: this is legacy,
     * fixture/seeder-only dead code (see this docblock's own opening
     * paragraph) superseded by teamClassification() below, which carries no
     * individual member names at all and has nothing to mask.
     *
     * E2.7 removes TeamResultsManager.tsx from the client results view
     * entirely (replaced by the live-computed classification table) rather
     * than leaving two competing "team results" UIs visible to an organizer
     * side by side. The `team_results` table and this method are left in
     * place (not dropped) purely to avoid an unrelated destructive migration
     * and to keep EventFixtureImporter/seeders working unmodified - but they
     * are now dead code from the product's perspective.
     *
     * @return array<int, array<string, mixed>>
     */
    public function teamResults(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, event_id AS eventId, distance_id AS distanceId, distance_name AS distanceName, team_name AS teamName, members, total_time AS totalTime, total_time_in_seconds AS totalTimeInSeconds, position FROM team_results WHERE event_id = :eventId ORDER BY position ASC');
        $stmt->execute(['eventId' => $eventId]);
        return array_map(static function (array $row): array {
            $row['members'] = $row['members'] ? json_decode((string) $row['members'], true) : [];
            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * E2.7 (spec 14 sekcja 4): "Wynik drużyny/sztafety jest liczony z
     * wyników indywidualnych członków, nie wpisywany osobno ... klasyfikacja
     * drużynowa to osobna tabela obok klasyfikacji indywidualnej na tym
     * samym Dystansie, nie osobny byt wyniku." See teamResults() above for
     * why this replaces (rather than reuses) the legacy `team_results` table.
     *
     * Grouping key: registrations.team_id shared by >1 (or even exactly 1 -
     * nothing here requires more than one member) registrations on this
     * event+distance - the lightweight grouping the spec/ticket calls for,
     * not a dedicated "Drużyna" entity (out of scope, stage 7). team_name is
     * read off whichever member row happens to carry it (they're expected to
     * agree - RegistrationRepository::create()/update() write the same
     * teamName to every member of a team at registration time).
     *
     * Roster membership: every registration on this event+distance with a
     * non-null team_id and a status other than cancelled/removed - i.e. the
     * same "still a real participant" bar `renumberEventDistance()` and
     * friends implicitly use elsewhere, not narrowed further to `confirmed`
     * only (a `pending` registration is still a roster member for team-size
     * purposes; it simply won't have a finished result yet, which the
     * completeness rule below already accounts for).
     *
     * Edge case - partial team (acceptance criterion 5): a team where not
     * every roster member has a `finished` result yet (no result row at all,
     * or a dnf/dns/dsq result) is EXCLUDED from the ranking entirely, not
     * shown with a partial/placeholder value. Reasoning: unlike an
     * individual classification (where a dnf/dns/dsq row is still shown,
     * just unranked - spec 14 sekcja 1), there is no meaningful "partial"
     * aggregate to display for sum/average (missing a member understates the
     * total) or even best (an incomplete team could still "win" on one fast
     * member despite not having finished as a team), so surfacing a
     * misleading number is worse than omitting the row until the team is
     * complete. This mirrors relay_team_completion_deadline's own framing
     * (migration 024): a relay team is expected to reach a complete roster
     * before it's evaluated as a team.
     *
     * Edge case - DNF/DNS/DSQ member (acceptance criterion 5): disqualifies
     * only that member's contribution to the TEAM aggregate (via the
     * completeness rule above, which excludes the whole team from this
     * table), never the member's own individual result - their row keeps
     * its own status/time/history untouched in the individual classification
     * (E2.2), exactly as spec 14 sekcja 1 already guarantees for individual
     * results. There is deliberately no partial-credit path (e.g. "drop the
     * DNF member and aggregate the rest") - the configured rule (sum/best/
     * average) is defined over "the team's members", and silently
     * redefining that set per row would make the aggregation rule mean
     * something different for every incomplete team.
     *
     * Aggregation rule: 'sum' (total of all finished members' GROSS
     * time_in_seconds - net is optional per spec 14 sekcja 2, so it can't be
     * the universal key, same reasoning as lifetimeRecordsForCompetitor()),
     * 'best' (the fastest single member time), or 'average' (arithmetic
     * mean). Falls back to 'sum' when the distance's
     * event_distances.team_aggregation_rule is NULL (e.g. a relay distance
     * created before this rule existed, or left unset) rather than throwing
     * or returning an empty table.
     *
     * Live query, not materialized: same reasoning as
     * lifetimeRecordsForCompetitor() above - this is a read path (E2.4
     * precedent), so a correction to any member's result (PATCH /results/{id})
     * or a fresh import is reflected on the very next call, with no
     * invalidation step to get wrong.
     *
     * @return array<int, array{teamId: string, teamName: string, memberCount: int, aggregationRule: string, aggregatedTimeInSeconds: float, position: int}>
     */
    public function teamClassification(string $eventId, string $distanceId): array
    {
        $ruleStmt = $this->pdo->prepare('SELECT team_aggregation_rule FROM event_distances WHERE id = :distanceId AND event_id = :eventId');
        $ruleStmt->execute(['distanceId' => $distanceId, 'eventId' => $eventId]);
        $configuredRule = $ruleStmt->fetchColumn();
        $rule = in_array($configuredRule, ['sum', 'best', 'average'], true) ? (string) $configuredRule : 'sum';

        $stmt = $this->pdo->prepare(
            'SELECT reg.team_id AS teamId, reg.team_name AS teamName, res.status AS resultStatus, res.time_in_seconds AS timeInSeconds
             FROM registrations reg
             LEFT JOIN results res ON res.registration_id = reg.id
             WHERE reg.event_id = :eventId AND reg.distance_id = :distanceId
               AND reg.team_id IS NOT NULL AND reg.status NOT IN ("cancelled", "removed")'
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);

        $teams = [];
        foreach ($stmt->fetchAll() as $row) {
            $teamId = (string) $row['teamId'];
            if (!isset($teams[$teamId])) {
                $teams[$teamId] = [
                    'teamId' => $teamId,
                    'teamName' => (string) $row['teamName'],
                    'memberCount' => 0,
                    'times' => [],
                    'complete' => true,
                ];
            }
            $teams[$teamId]['memberCount']++;
            if ($row['resultStatus'] === 'finished' && $row['timeInSeconds'] !== null) {
                $teams[$teamId]['times'][] = (float) $row['timeInSeconds'];
            } else {
                // Missing result row (no import/manual entry yet) or a
                // non-finished status (dnf/dns/dsq) - either way this member
                // has no qualifying time, so the team is not yet complete.
                $teams[$teamId]['complete'] = false;
            }
        }

        $classification = [];
        foreach ($teams as $team) {
            if (!$team['complete']) {
                continue;
            }
            $times = $team['times'];
            $aggregated = match ($rule) {
                'best' => min($times),
                'average' => array_sum($times) / count($times),
                default => array_sum($times),
            };
            $classification[] = [
                'teamId' => $team['teamId'],
                'teamName' => $team['teamName'],
                'memberCount' => $team['memberCount'],
                'aggregationRule' => $rule,
                'aggregatedTimeInSeconds' => round($aggregated, 2),
                'position' => 0,
            ];
        }

        usort($classification, static fn (array $a, array $b): int => $a['aggregatedTimeInSeconds'] <=> $b['aggregatedTimeInSeconds']);
        foreach ($classification as $index => &$row) {
            $row['position'] = $index + 1;
        }
        unset($row);

        return $classification;
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
