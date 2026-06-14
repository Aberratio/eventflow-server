<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

use ZmierzymyCzas\Domain\Repository;

final class EventRepository extends Repository
{
    private const CREDIT_KINDS = ['standard', 'virtual', 'timing'];
    private const EVENT_STATUSES = ['draft', 'awaiting_approval', 'approved', 'active', 'past', 'archived', 'deleted'];

    private const EVENT_COLUMNS = <<<'SQL'
id, name, slug, discipline, date, location, location_maps_url AS locationMapsUrl, city, description, image_url AS imageUrl,
original_image_url AS originalImageUrl,
series_id AS seriesId, organizer_id AS organizerId, organizer_name AS organizerName,
organizer_contact AS organizerContact, status, rejection_reason AS rejectionReason,
submitted_at AS submittedAt, approved_at AS approvedAt, approved_by AS approvedBy,
published_at AS publishedAt, published_by AS publishedBy, created_by AS createdBy,
registration_deadline AS registrationDeadline,
registration_open_date AS registrationOpenDate,
registration_until_event_start AS registrationUntilEventStart, featured,
live_results_url AS liveResultsUrl, is_live AS isLive, registration_status AS registrationStatus,
route_map_url AS routeMapUrl, route_gpx_url AS routeGpxUrl, route_elevation_profile AS routeElevationProfile,
banner_url AS bannerUrl, original_banner_url AS originalBannerUrl,
banner_crop_area AS bannerCropArea, organizer_logo_url AS organizerLogoUrl,
original_organizer_logo_url AS originalOrganizerLogoUrl,
organizer_logo_shape AS organizerLogoShape,
is_virtual AS isVirtual, event_credit_kind AS eventCreditKind, start_date AS startDate, end_date AS endDate, payment_config AS paymentConfig,
rules_html AS rulesHtml, final_acceptance_html AS finalAcceptanceHtml,
require_final_acceptance AS requireFinalAcceptance, is_private AS isPrivate, access_code AS accessCode,
registration_embed AS registrationEmbed, registration_form_config AS registrationFormConfig,
display_options AS displayOptions, logistics_info AS logisticsInfo,
organizer_info AS organizerInfo
SQL;

    private const EVENT_JSON = [
        'organizerContact',
        'bannerCropArea',
        'paymentConfig',
        'registrationEmbed',
        'registrationFormConfig',
        'displayOptions',
        'logisticsInfo',
        'organizerInfo',
    ];

    private const DISTANCE_JSON = [
        'categories',
        'ageCategories',
        'specialCategories',
        'priceTiers',
        'vipPrices',
        'routeFiles',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        foreach ([
            'discipline' => 'discipline',
            'city' => 'city',
            'status' => 'status',
            'seriesId' => 'series_id',
            'registrationStatus' => 'registration_status',
        ] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (empty($filters['includePrivate'])) {
            $where[] = 'is_private = 0';
        }
        if (empty($filters['includeUnpublished'])) {
            $where[] = "status IN ('active', 'past', 'archived')";
        } elseif (empty($filters['includeDeleted'])) {
            $where[] = "status <> 'deleted'";
        }
        if (!empty($filters['dateFrom'])) {
            $where[] = 'date >= :dateFrom';
            $params['dateFrom'] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where[] = 'date <= :dateTo';
            $params['dateTo'] = $filters['dateTo'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :searchName OR city LIKE :searchCity OR location LIKE :searchLocation)';
            $search = '%' . $filters['search'] . '%';
            $params['searchName'] = $search;
            $params['searchCity'] = $search;
            $params['searchLocation'] = $search;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagination = $this->pagination($filters);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM events{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . " FROM events{$whereSql} ORDER BY date ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $pagination['pageSize'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $pagination['offset'], \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll()),
            'total' => $total,
            'page' => $pagination['page'],
            'pageSize' => $pagination['pageSize'],
        ];
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . ' FROM events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . ' FROM events WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function featured(): array
    {
        $stmt = $this->pdo->query("SELECT " . self::EVENT_COLUMNS . " FROM events WHERE featured = 1 AND status IN ('active', 'past', 'archived') ORDER BY date ASC");
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function live(): array
    {
        $stmt = $this->pdo->query("SELECT " . self::EVENT_COLUMNS . " FROM events WHERE is_live = 1 AND status IN ('active', 'past', 'archived') ORDER BY date ASC");
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function byOrganizer(string $organizerId, bool $includeDeleted = false): array
    {
        $deletedSql = $includeDeleted ? '' : " AND status <> 'deleted'";
        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . " FROM events WHERE organizer_id = :organizerId{$deletedSql} ORDER BY date ASC");
        $stmt->execute(['organizerId' => $organizerId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->insert('events', $this->eventData($id, $data, true));
        $this->replaceChildren($id, $data);
        return $this->find($id);
    }

    /** @param array<string, mixed> $data */
    public function createForOrganizer(array $data, array $organizer, string $actorId): array
    {
        $id = $this->id();
        $this->pdo->beginTransaction();
        try {
            $payload = $data;
            $payload['organizerId'] = $organizer['id'];
            $payload['organizerName'] = $organizer['companyName'] ?: $organizer['name'];
            $payload['status'] = 'draft';
            $payload['createdBy'] = $actorId;
            $payload['eventCreditKind'] = $this->creditKindForEvent($payload);
            $this->assertOrganizerHasCapacity((string) $organizer['id']);
            $this->insert('events', $this->eventData($id, $payload, true));
            $this->replaceChildren($id, $payload);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $this->updateById('events', $id, $this->eventData($id, $data, false));
        $this->replaceChildren($id, $data);
        return $this->find($id);
    }

    /** @param array<string, mixed> $organizer */
    public function transferOrganizer(string $id, array $organizer): void
    {
        $event = $this->find($id);
        if (!$event || $event['organizerId'] === $organizer['id']) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            if (($event['status'] ?? '') !== 'deleted') {
                $this->assertOrganizerHasCapacity((string) $organizer['id']);
            }
            $this->updateById('events', $id, [
                'organizer_id' => $organizer['id'],
                'organizer_name' => $organizer['companyName'] ?: $organizer['name'],
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(string $id): void
    {
        $this->updateById('events', $id, ['status' => 'deleted']);
    }

    public function updateStatus(string $id, string $status, ?string $reason): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['status'] === 'deleted') {
            return null;
        }

        $status = $this->normalizeStatus($status);
        $this->assertStatusTransition($event, $status);
        $this->updateById('events', $id, [
            'status' => $status,
            'rejection_reason' => $reason,
        ]);
        return $this->find($id);
    }

    public function submit(string $id): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['status'] === 'deleted') {
            return null;
        }
        if ((string) $event['status'] !== 'draft') {
            return null;
        }
        $this->updateById('events', $id, [
            'status' => 'awaiting_approval',
            'rejection_reason' => null,
            'submitted_at' => date(DATE_ATOM),
        ]);
        return $this->find($id);
    }

    public function approve(string $id, string $actorId): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['status'] !== 'awaiting_approval') {
            return null;
        }
        $this->updateById('events', $id, [
            'status' => 'approved',
            'rejection_reason' => null,
            'approved_at' => date(DATE_ATOM),
            'approved_by' => $actorId,
        ]);
        return $this->find($id);
    }

    public function reject(string $id, string $reason): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['status'] !== 'awaiting_approval') {
            return null;
        }
        $this->updateById('events', $id, [
            'status' => 'draft',
            'rejection_reason' => $reason,
        ]);
        return $this->find($id);
    }

    public function publish(string $id, string $actorId): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['status'] !== 'approved') {
            return null;
        }
        $this->updateById('events', $id, [
            'status' => 'active',
            'published_at' => date(DATE_ATOM),
            'published_by' => $actorId,
        ]);
        return $this->find($id);
    }

    /** @return array{limit: int, used: int, available: int} */
    public function eventLimit(string $organizerId): array
    {
        $stmt = $this->pdo->prepare('SELECT event_limit FROM users WHERE id = :organizerId AND role = :role');
        $stmt->execute(['organizerId' => $organizerId, 'role' => 'organizer']);
        $limit = (int) ($stmt->fetchColumn() ?: 0);
        $used = $this->activeEventCount($organizerId);

        return [
            'limit' => $limit,
            'used' => $used,
            'available' => max(0, $limit - $used),
        ];
    }

    /** @return array{limit: int, used: int, available: int} */
    public function updateEventLimit(string $organizerId, int $limit): array
    {
        $used = $this->activeEventCount($organizerId);
        if ($limit < $used) {
            throw new \RuntimeException('event_limit_below_usage');
        }

        $this->pdo->prepare('UPDATE users SET event_limit = :limit WHERE id = :organizerId AND role = :role')
            ->execute(['limit' => $limit, 'organizerId' => $organizerId, 'role' => 'organizer']);

        return $this->eventLimit($organizerId);
    }

    /** @return array{total: int, used: int, available: int, byType: array<string, array{total: int, used: int, available: int}>} */
    public function credits(string $organizerId): array
    {
        $stmt = $this->pdo->prepare('SELECT event_credit_kind AS kind, COALESCE(SUM(total_count), 0) AS total, COALESCE(SUM(used_count), 0) AS used FROM organizer_event_credit_batches WHERE organizer_id = :organizerId AND (expires_at IS NULL OR expires_at >= :now) GROUP BY event_credit_kind');
        $stmt->execute(['organizerId' => $organizerId, 'now' => date(DATE_ATOM)]);

        $byType = [];
        foreach (self::CREDIT_KINDS as $kind) {
            $byType[$kind] = ['total' => 0, 'used' => 0, 'available' => 0];
        }
        foreach ($stmt->fetchAll() as $row) {
            $kind = $this->normalizeCreditKind((string) ($row['kind'] ?? 'standard'));
            $total = (int) $row['total'];
            $used = (int) $row['used'];
            $byType[$kind] = ['total' => $total, 'used' => $used, 'available' => max(0, $total - $used)];
        }

        return [
            'total' => array_sum(array_column($byType, 'total')),
            'used' => array_sum(array_column($byType, 'used')),
            'available' => array_sum(array_column($byType, 'available')),
            'byType' => $byType,
        ];
    }

    public function grantCredits(string $organizerId, int $count, string $sourceType = 'manual', ?string $sourceId = null, string $kind = 'standard'): array
    {
        $this->insert('organizer_event_credit_batches', [
            'id' => $this->id(),
            'organizer_id' => $organizerId,
            'event_credit_kind' => $this->normalizeCreditKind($kind),
            'total_count' => $count,
            'used_count' => 0,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_at' => date(DATE_ATOM),
            'expires_at' => null,
        ]);

        return $this->credits($organizerId);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        $event = $this->decode($row, self::EVENT_JSON);
        foreach (['featured', 'isLive', 'isVirtual', 'requireFinalAcceptance', 'isPrivate', 'registrationUntilEventStart'] as $bool) {
            $event[$bool] = (bool) ($event[$bool] ?? false);
        }
        $event['distances'] = $this->withPaidCounts(
            $event['id'],
            $this->children('event_distances', 'event_id', $event['id'], self::DISTANCE_JSON)
        );
        $event['paymentMethods'] = $this->children('event_payment_methods', 'event_id', $event['id']);
        $event['merchandise'] = $this->children('event_merchandise', 'event_id', $event['id'], ['sizes', 'colors']);
        $event['discountCodes'] = $this->children('event_discount_codes', 'event_id', $event['id'], ['distanceIds', 'usedBy']);
        $event['galleryAlbums'] = $this->children('event_gallery_albums', 'event_id', $event['id']);
        $event['resultDocuments'] = $this->children('event_result_documents', 'event_id', $event['id']);
        $event['documents'] = $this->children('event_documents', 'event_id', $event['id']);

        return $event;
    }

    /**
     * @param array<int, array<string, mixed>> $distances
     * @return array<int, array<string, mixed>>
     */
    private function withPaidCounts(string $eventId, array $distances): array
    {
        if ($distances === []) {
            return $distances;
        }

        $stmt = $this->pdo->prepare("SELECT r.distance_id AS distanceId, COUNT(*) AS cnt FROM registrations r WHERE r.event_id = :eventId AND r.status NOT IN ('removed', 'cancelled') GROUP BY r.distance_id");
        $stmt->execute(['eventId' => $eventId]);
        $registered = [];
        foreach ($stmt->fetchAll() as $row) {
            $registered[(string) $row['distanceId']] = (int) $row['cnt'];
        }

        $stmt = $this->pdo->prepare('SELECT r.distance_id AS distanceId, COUNT(*) AS paidCount FROM registrations r INNER JOIN registration_payment_details pd ON pd.registration_id = r.id WHERE r.event_id = :eventId AND r.status <> :removed AND pd.start_fee_paid = 1 GROUP BY r.distance_id');
        $stmt->execute(['eventId' => $eventId, 'removed' => 'removed']);
        $paid = [];
        foreach ($stmt->fetchAll() as $row) {
            $paid[(string) $row['distanceId']] = (int) $row['paidCount'];
        }

        return array_map(static function (array $distance) use ($registered, $paid): array {
            $id = (string) ($distance['id'] ?? '');
            $distance['registeredCount'] = $registered[$id] ?? 0;
            $distance['paidCount'] = $paid[$id] ?? 0;
            return $distance;
        }, $distances);
    }

    /**
     * @param array<int, string> $jsonFields
     * @return array<int, array<string, mixed>>
     */
    private function children(string $table, string $foreignKey, string $id, array $jsonFields = []): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE {$foreignKey} = :id ORDER BY sort_order ASC, id ASC");
        $stmt->execute(['id' => $id]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            unset($row[$foreignKey], $row['sort_order']);
            $camelized = $this->camelizeKeys($row);
            if ($table === 'event_distances') {
                $camelized['limit'] = $camelized['participantLimit'] ?? null;
                unset($camelized['participantLimit']);
            }
            foreach (['enabled', 'available', 'active'] as $boolField) {
                if (array_key_exists($boolField, $camelized)) {
                    $camelized[$boolField] = (bool) $camelized[$boolField];
                }
            }
            $rows[] = $this->decode($camelized, $jsonFields);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function eventData(string $id, array $data, bool $creating): array
    {
        $map = [
            'name' => 'name',
            'slug' => 'slug',
            'discipline' => 'discipline',
            'date' => 'date',
            'location' => 'location',
            'locationMapsUrl' => 'location_maps_url',
            'city' => 'city',
            'description' => 'description',
            'imageUrl' => 'image_url',
            'originalImageUrl' => 'original_image_url',
            'seriesId' => 'series_id',
            'organizerId' => 'organizer_id',
            'organizerName' => 'organizer_name',
            'organizerContact' => 'organizer_contact',
            'organizerInfo' => 'organizer_info',
            'status' => 'status',
            'rejectionReason' => 'rejection_reason',
            'submittedAt' => 'submitted_at',
            'approvedAt' => 'approved_at',
            'approvedBy' => 'approved_by',
            'publishedAt' => 'published_at',
            'publishedBy' => 'published_by',
            'createdBy' => 'created_by',
            'registrationDeadline' => 'registration_deadline',
            'registrationOpenDate' => 'registration_open_date',
            'registrationUntilEventStart' => 'registration_until_event_start',
            'featured' => 'featured',
            'liveResultsUrl' => 'live_results_url',
            'isLive' => 'is_live',
            'registrationStatus' => 'registration_status',
            'routeMapUrl' => 'route_map_url',
            'routeGpxUrl' => 'route_gpx_url',
            'routeElevationProfile' => 'route_elevation_profile',
            'bannerUrl' => 'banner_url',
            'originalBannerUrl' => 'original_banner_url',
            'bannerCropArea' => 'banner_crop_area',
            'organizerLogoUrl' => 'organizer_logo_url',
            'originalOrganizerLogoUrl' => 'original_organizer_logo_url',
            'organizerLogoShape' => 'organizer_logo_shape',
            'isVirtual' => 'is_virtual',
            'eventCreditKind' => 'event_credit_kind',
            'startDate' => 'start_date',
            'endDate' => 'end_date',
            'paymentConfig' => 'payment_config',
            'rulesHtml' => 'rules_html',
            'finalAcceptanceHtml' => 'final_acceptance_html',
            'requireFinalAcceptance' => 'require_final_acceptance',
            'isPrivate' => 'is_private',
            'accessCode' => 'access_code',
            'registrationEmbed' => 'registration_embed',
            'registrationFormConfig' => 'registration_form_config',
            'displayOptions' => 'display_options',
            'logisticsInfo' => 'logistics_info',
        ];
        $out = $creating ? ['id' => $id, 'status' => 'draft', 'featured' => 0] : [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $value = is_bool($data[$input]) ? (int) $data[$input] : $data[$input];
                $out[$column] = $input === 'status' ? $this->normalizeStatus((string) $value) : $value;
            }
        }
        foreach (['organizer_contact', 'banner_crop_area', 'payment_config', 'registration_embed', 'registration_form_config', 'display_options', 'logistics_info', 'organizer_info'] as $jsonField) {
            if (array_key_exists($jsonField, $out) && $out[$jsonField] !== null && !is_string($out[$jsonField])) {
                $out[$jsonField] = json_encode($out[$jsonField], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $out;
    }

    private function consumeCredit(string $organizerId, string $eventId, string $kind): void
    {
        $kind = $this->normalizeCreditKind($kind);
        $stmt = $this->pdo->prepare('SELECT id, total_count, used_count FROM organizer_event_credit_batches WHERE organizer_id = :organizerId AND event_credit_kind = :kind AND used_count < total_count AND (expires_at IS NULL OR expires_at >= :now) ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE');
        $stmt->execute(['organizerId' => $organizerId, 'kind' => $kind, 'now' => date(DATE_ATOM)]);
        $batch = $stmt->fetch();
        if (!$batch) {
            throw new \RuntimeException('no_event_credits');
        }

        $this->pdo->prepare('UPDATE organizer_event_credit_batches SET used_count = used_count + 1 WHERE id = :id')->execute(['id' => $batch['id']]);
        $this->insert('organizer_event_credit_usages', [
            'id' => $this->id(),
            'event_id' => $eventId,
            'organizer_id' => $organizerId,
            'batch_id' => $batch['id'],
            'event_credit_kind' => $kind,
            'used_at' => date(DATE_ATOM),
        ]);
    }

    /** @param array<string, mixed> $event */
    private function creditKindForEvent(array $event): string
    {
        if (!empty($event['eventCreditKind'])) {
            return $this->normalizeCreditKind((string) $event['eventCreditKind']);
        }
        return !empty($event['isVirtual']) ? 'virtual' : 'standard';
    }

    private function normalizeCreditKind(string $kind): string
    {
        return in_array($kind, self::CREDIT_KINDS, true) ? $kind : 'standard';
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'published' => 'active',
            'completed' => 'past',
            'cancelled' => 'archived',
            'rejected' => 'draft',
            default => in_array($status, self::EVENT_STATUSES, true) ? $status : 'draft',
        };
    }

    /** @param array<string, mixed> $event */
    private function assertStatusTransition(array $event, string $status): void
    {
        if ($status === 'archived' && !$this->hasEventEnded($event)) {
            throw new \RuntimeException('event_archive_before_start');
        }

        if ($status === (string) ($event['status'] ?? '')) {
            return;
        }

        if (!in_array($status, ['draft', 'past', 'archived'], true)) {
            throw new \RuntimeException('invalid_status_transition');
        }
    }

    private function activeEventCount(string $organizerId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM events WHERE organizer_id = :organizerId AND status <> 'deleted'");
        $stmt->execute(['organizerId' => $organizerId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $event */
    public function canDeleteBeforeRegistrationOpened(array $event): bool
    {
        if (in_array((string) ($event['status'] ?? ''), ['past', 'archived', 'deleted'], true)) {
            return false;
        }

        $eventDate = (string) ($event['date'] ?? '');
        if ($eventDate !== '' && substr($eventDate, 0, 10) < date('Y-m-d')) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = :eventId AND status <> 'removed'");
        $stmt->execute(['eventId' => $event['id']]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** @param array<string, mixed> $event */
    private function hasEventEnded(array $event): bool
    {
        $endDate = (string) (($event['endDate'] ?? '') ?: ($event['date'] ?? ''));
        if ($endDate === '') {
            return false;
        }

        return substr($endDate, 0, 10) < date('Y-m-d');
    }

    private function assertOrganizerHasCapacity(string $organizerId): void
    {
        $limit = $this->eventLimit($organizerId);
        if ($limit['used'] >= $limit['limit']) {
            throw new \RuntimeException('event_limit_exceeded');
        }
    }

    private function releaseCredit(string $eventId): void
    {
        $stmt = $this->pdo->prepare('SELECT batch_id FROM organizer_event_credit_usages WHERE event_id = :eventId FOR UPDATE');
        $stmt->execute(['eventId' => $eventId]);
        $usage = $stmt->fetch();
        if (!$usage) {
            return;
        }

        $this->pdo->prepare('UPDATE organizer_event_credit_batches SET used_count = GREATEST(used_count - 1, 0) WHERE id = :id')->execute(['id' => $usage['batch_id']]);
        $this->pdo->prepare('DELETE FROM organizer_event_credit_usages WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
    }

    /** @param array<string, mixed> $data */
    private function replaceChildren(string $eventId, array $data): void
    {
        $children = [
            'distances' => ['table' => 'event_distances', 'json' => self::DISTANCE_JSON],
            'paymentMethods' => ['table' => 'event_payment_methods', 'json' => []],
            'merchandise' => ['table' => 'event_merchandise', 'json' => ['sizes', 'colors']],
            'discountCodes' => ['table' => 'event_discount_codes', 'json' => ['distanceIds', 'usedBy']],
            'galleryAlbums' => ['table' => 'event_gallery_albums', 'json' => []],
        ];

        $children['documents'] = ['table' => 'event_documents', 'json' => []];

        foreach ($children as $input => $meta) {
            if (!array_key_exists($input, $data)) {
                continue;
            }
            $this->pdo->prepare("DELETE FROM {$meta['table']} WHERE event_id = :eventId")->execute(['eventId' => $eventId]);
            foreach (array_values($data[$input] ?? []) as $index => $item) {
                $row = $this->snakeizeKeys($item);
                if ($meta['table'] === 'event_distances' && array_key_exists('limit', $row)) {
                    $row['participant_limit'] = $row['limit'];
                    unset($row['limit']);
                }
                $row['id'] = $row['id'] ?? $this->id();
                $row['event_id'] = $eventId;
                $row['sort_order'] = $index;
                foreach ($meta['json'] as $jsonField) {
                    $snake = $this->snake($jsonField);
                    if (array_key_exists($snake, $row) && !is_string($row[$snake])) {
                        $row[$snake] = json_encode($row[$snake], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
                $this->insert($meta['table'], $row);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function camelizeKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[preg_replace_callback('/_([a-z])/', static fn ($m) => strtoupper($m[1]), $key)] = $value;
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function snakeizeKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$this->snake((string) $key)] = $value;
        }
        return $out;
    }

    private function snake(string $key): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }
}
