<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

use Psr\Log\LoggerInterface;
use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Support\HtmlSanitizer;
use ZmierzymyCzas\Support\InputRules;
use ZmierzymyCzas\Support\InputValidationException;
use ZmierzymyCzas\Support\Log\LoggerFactory;

final class EventRepository extends Repository
{
    /** PP.59: PSR-3 logger for dropped child keys; defaults to the error_log-backed fallback. */
    private readonly LoggerInterface $logger;

    public function __construct(\PDO $pdo, ?LoggerInterface $logger = null)
    {
        parent::__construct($pdo);
        $this->logger = $logger ?? LoggerFactory::fallback();
    }

    private const CREDIT_KINDS = ['standard', 'virtual', 'timing'];

    // PP.5: rich-text columns sanitized on write via HtmlSanitizer (second line of
    // defence; the client also sanitizes on every render).
    private const RICH_TEXT_COLUMNS = ['description', 'rules_html', 'organizers_html', 'final_acceptance_html'];

    /**
     * E1.4: the two payment-window durations (docs/20-... pkt 1) live as two more
     * keys inside the already-existing `payment_config` JSON blob (001_init.sql) —
     * no schema change needed, same column the organizer already edits for
     * type/bank-account fields via the generic PATCH /events/{id}. These are the
     * defaults applied in hydrate() whenever an event's paymentConfig doesn't carry
     * its own values yet (a brand-new event, or one created before this ticket) —
     * "bramka: domyślnie 30-60 minut" / "przelew: domyślnie 3-5 dni roboczych".
     */
    private const DEFAULT_GATEWAY_WINDOW_MINUTES = 45;
    private const DEFAULT_TRANSFER_WINDOW_DAYS = 3;

    /**
     * E3.2: Biuro Zawodów (BZ) integration toggle per Wydarzenie (issue 12 §3). Unlike
     * `payment_config`, this is a single small toggle+string, not a multi-field JSON
     * blob — so it lives as two flat columns (047_e3_2_...sql), the same shape as
     * E1.1's `visibility`/`is_test` rather than a JSON column. The platform never
     * creates a BZ organization itself: the organizer pastes an existing organization
     * token/identifier when flipping the switch on. `bz_organization_token` is
     * intentionally never wiped when disabling — routes.php's generic PATCH only
     * writes columns present in the request body (see eventData() below), so a
     * disable-only PATCH leaves the stored token untouched, matching how other
     * optional per-Event toggles in this codebase preserve their associated value.
     * The token must never reach a non-manager read — see routes.php's
     * `$stripBzToken` applied to every public/anonymous Event read path.
     */

    /**
     * The workflow axis (E1.1). `approved` and `rejected` are legal ENUM values on the
     * column (schema future-proofing, per spec) but the application never actually rests
     * a row there: approve() lands directly on `published`, reject() lands directly on
     * `draft` (with rejection_reason kept for the UI). `cancelled` is terminal and manual.
     * Soft-delete used to be folded into this same field (`status = 'deleted'`) — E1.1
     * splits that out to its own `deleted_at` column, since "is this row gone" is not part
     * of the publication workflow.
     */
    private const PUBLICATION_STATUSES = ['draft', 'submitted', 'approved', 'published', 'finished', 'rejected', 'cancelled'];

    private const EVENT_COLUMNS = <<<'SQL'
id, name, slug, discipline, date, location, location_maps_url AS locationMapsUrl, city, description, image_url AS imageUrl,
original_image_url AS originalImageUrl,
series_id AS seriesId, organizer_id AS organizerId, organizer_name AS organizerName,
organizer_contact AS organizerContact,
publication_status AS publicationStatus, visibility, is_test AS isTest, is_external AS isExternal, deleted_at AS deletedAt,
rejection_reason AS rejectionReason,
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
rules_html AS rulesHtml, organizers_html AS organizersHtml,
insurance_option AS insuranceOption,
bz_integration_enabled AS bzIntegrationEnabled, bz_organization_token AS bzOrganizationToken,
enable_merchandise_in_registration AS enableMerchandiseInRegistration,
final_acceptance_html AS finalAcceptanceHtml,
require_final_acceptance AS requireFinalAcceptance, is_private AS isPrivate, access_code AS accessCode,
registration_embed AS registrationEmbed, registration_form_config AS registrationFormConfig,
embed_enabled AS embedEnabled, embed_allowed_origins AS embedAllowedOrigins,
display_options AS displayOptions, logistics_info AS logisticsInfo,
organizer_info AS organizerInfo,
background_id AS backgroundId
SQL;

    private const EVENT_JSON = [
        'organizerContact',
        'bannerCropArea',
        'paymentConfig',
        'registrationEmbed',
        'registrationFormConfig',
        'embedAllowedOrigins',
        'displayOptions',
        'logisticsInfo',
        'organizerInfo',
        'insuranceOption',
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
     * @param array{userId: string, isOrganizer: bool}|null $privateViewer PP.55: set by the route only;
     *        null = no private Events unless `includePrivate` (staff) is set.
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = [], ?array $privateViewer = null): array
    {
        $this->lazilyFinishPastEvents();
        $where = [];
        $params = [];

        foreach ([
            'discipline' => 'discipline',
            'city' => 'city',
            'seriesId' => 'series_id',
            'registrationStatus' => 'registration_status',
        ] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (!empty($filters['status'])) {
            $where[] = 'publication_status = :status';
            $params['status'] = $this->normalizeStatus((string) $filters['status']);
        }
        if (empty($filters['includePrivate'])) {
            if ($privateViewer !== null && $privateViewer['userId'] !== '') {
                if ($privateViewer['isOrganizer']) {
                    $where[] = '(is_private = 0 OR organizer_id = :privateViewerOrganizerId)';
                    $params['privateViewerOrganizerId'] = $privateViewer['userId'];
                } else {
                    $zapisExists = 'EXISTS (SELECT 1 FROM registrations pv_r WHERE pv_r.event_id = events.id'
                        . ' AND (pv_r.user_id = :privateViewerUserId OR pv_r.registered_by_id = :privateViewerDelegateId))';
                    $where[] = "(is_private = 0 OR {$zapisExists})";
                    $params['privateViewerUserId'] = $privateViewer['userId'];
                    $params['privateViewerDelegateId'] = $privateViewer['userId'];
                }
            } else {
                $where[] = 'is_private = 0';
            }
        }
        if (array_key_exists('isExternal', $filters) && $filters['isExternal'] !== '') {
            $where[] = 'is_external = :isExternal';
            $params['isExternal'] = !empty($filters['isExternal']) ? 1 : 0;
        }
        if (empty($filters['includeUnpublished'])) {
            $where[] = "publication_status IN ('published', 'finished')";
            $where[] = 'deleted_at IS NULL';
        } elseif (empty($filters['includeDeleted'])) {
            $where[] = 'deleted_at IS NULL';
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
        $this->lazilyFinishPastEvents();
        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . ' FROM events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $this->lazilyFinishPastEvents();
        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . ' FROM events WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function featured(): array
    {
        $this->lazilyFinishPastEvents();
        $stmt = $this->pdo->query("SELECT " . self::EVENT_COLUMNS . " FROM events WHERE featured = 1 AND is_private = 0 AND deleted_at IS NULL AND publication_status IN ('published', 'finished') ORDER BY date ASC");
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function live(): array
    {
        $this->lazilyFinishPastEvents();
        $stmt = $this->pdo->query("SELECT " . self::EVENT_COLUMNS . " FROM events WHERE is_live = 1 AND is_private = 0 AND deleted_at IS NULL AND publication_status IN ('published', 'finished') ORDER BY date ASC");
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function byOrganizer(string $organizerId, bool $includeDeleted = false): array
    {
        $this->lazilyFinishPastEvents();
        $deletedSql = $includeDeleted ? '' : ' AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . " FROM events WHERE organizer_id = :organizerId{$deletedSql} ORDER BY date ASC");
        $stmt->execute(['organizerId' => $organizerId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        return $this->createWithId($this->id(), $data);
    }

    /**
     * Creates an event under a caller-supplied id. Only the database seeder uses this:
     * fixtures have to keep their ids so registrations and results still line up.
     *
     * @param array<string, mixed> $data
     */
    public function createWithId(string $id, array $data): array
    {
        // Trusted: the array is built on the server (seeder, dev seed route, tests), so fixture ids
        // and the system counters survive. Validation runs before the first write.
        $prepared = $this->prepareChildren($data, true);
        $this->inOwnTransaction(function () use ($id, $data, $prepared): void {
            $this->insert('events', $this->eventData($id, $data, true));
            $this->syncChildren($id, $prepared);
        });
        return $this->find($id);
    }

    /** @param array<string, mixed> $data */
    public function createForOrganizer(array $data, array $organizer, string $actorId): array
    {
        $id = $this->id();
        $payload = $data;
        $payload['organizerId'] = $organizer['id'];
        $payload['organizerName'] = $organizer['companyName'] ?: $organizer['name'];
        $payload['publicationStatus'] = 'draft';
        $payload['createdBy'] = $actorId;
        // PP.59: untrusted HTTP body, validated before the transaction (and any write) starts.
        $prepared = $this->prepareChildren($payload, false);
        $this->pdo->beginTransaction();
        try {
            $payload['eventCreditKind'] = $this->creditKindForEvent($payload);
            $this->assertOrganizerHasCapacity((string) $organizer['id']);
            $this->insert('events', $this->eventData($id, $payload, true));
            $this->syncChildren($id, $prepared);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * E6.4 (issue 15 §3): a barebones "Wydarzenie zewnętrzne" — the reference
     * point a Moderator/Admin creates so a Zawodnik's manual challenge-entry
     * proof (a Korona half-marathon that never ran on this platform, or a
     * pre-platform completion) can point at a real Event row instead of
     * free text. Deliberately minimal per the ticket's own scoping: no
     * `distances`/registration payload is ever accepted here (unlike
     * create()/createForOrganizer(), which both call syncChildren()), so
     * an external Event can never accidentally grow Dystans or registration
     * machinery. Always created already `published` (there is no
     * organizer-submission workflow to run it through) and with
     * `registrationStatus = 'none'` — "no distances, no registrations" per
     * spec — so it never appears as joinable to a Zawodnik browsing Events.
     *
     * @param array<string, mixed> $data expects name/date/location/city;
     *   discipline/description/imageUrl/registrationDeadline are defaulted
     *   when omitted since this Event never goes through a real
     *   organizer-facing creation form.
     */
    public function createExternal(array $data, string $actorId): array
    {
        $id = $this->id();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Nazwa wydarzenia zewnętrznego jest wymagana.');
        }
        $payload = [
            'name' => $name,
            'slug' => $this->uniqueExternalSlug($name, $id),
            'discipline' => in_array($data['discipline'] ?? null, ['running', 'cycling', 'triathlon', 'multisport'], true)
                ? $data['discipline']
                : 'running',
            'date' => (string) ($data['date'] ?? ''),
            'location' => (string) ($data['location'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'description' => (string) ($data['description'] ?? 'Wydarzenie zewnętrzne — bez Dystansów i zapisów na platformie.'),
            'imageUrl' => (string) ($data['imageUrl'] ?? ''),
            'organizerId' => $actorId,
            'organizerName' => (string) ($data['organizerName'] ?? 'Wydarzenie zewnętrzne'),
            'publicationStatus' => 'published',
            'publishedAt' => date(DATE_ATOM),
            'publishedBy' => $actorId,
            'createdBy' => $actorId,
            'registrationDeadline' => (string) ($data['date'] ?? ''),
            'registrationStatus' => 'none',
            'visibility' => 'public',
            'isExternal' => true,
        ];
        $this->insert('events', $this->eventData($id, $payload, true));
        return $this->find($id);
    }

    private function uniqueExternalSlug(string $name, string $id): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $base = $base !== '' ? $base : 'wydarzenie-zewnetrzne';
        return $base . '-' . substr($id, 0, 8);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $prepared = $this->prepareChildren($data, false);
        $this->inOwnTransaction(function () use ($id, $data, $prepared): void {
            $this->updateById('events', $id, $this->eventData($id, $data, false));
            $this->syncChildren($id, $prepared);
        });
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
            if ($event['deletedAt'] === null) {
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

    /**
     * Soft delete (E1.1). Used to be `status = 'deleted'`, folded into the same column
     * as the moderation workflow; split out to its own timestamp because "is this row
     * gone" isn't part of the publication_status axis.
     */
    public function delete(string $id): void
    {
        $this->updateById('events', $id, ['deleted_at' => date(DATE_ATOM)]);
    }

    /**
     * Pre-E1.1 this was a free-form status PATCH (draft/past/archived, staff- or
     * organizer-triggered). E1.1 replaces every one of those manual targets: `past`/
     * `archived` are now the automatic lazy Published→Finished check
     * (lazilyFinishPastEvents(), below), and the remaining transitions each have their
     * own method — submit()/approve()/reject()/cancel()/setVisibility()/setIsTest().
     * Kept only as a repository-level guard against writing publication_status directly
     * and skipping the moderation workflow: every manual target is rejected, matching
     * the "manual status change cannot bypass moderation" contract this repository has
     * always enforced.
     */
    public function updateStatus(string $id, string $status, ?string $reason): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['deletedAt'] !== null) {
            return null;
        }

        throw new \RuntimeException('invalid_status_transition');
    }

    /**
     * Draft -> Submitted. Also clears is_test irreversibly (E1.1: the flag only exists to
     * let an organizer walk the registration form before moderation; once submitted for
     * real, the event can never go back into test mode, even if rejected back to Draft).
     *
     * E5.6 (issue 16 §2, umowa §5.5): this is where the "pakiet organizatora" credit is
     * actually spent — never at Draft creation, never at publication. An organizer may
     * freely create and edit Drafts; the first thing that costs a credit is asking a
     * moderator to look at it. No available credit is a 4xx business error
     * (`event_submit_no_organizer_credit`), not a silent no-op — the event stays in
     * Draft, untouched, same as any other failed transition here. The decrement and the
     * status change happen in one transaction so the two can never desync (a crash
     * between them would otherwise either spend a credit with no submission to show for
     * it, or grant a free submission).
     */
    public function submit(string $id): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['deletedAt'] !== null) {
            return null;
        }
        if ((string) $event['publicationStatus'] !== 'draft') {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->consumeOrganizerPackageCredit((string) $event['organizerId']);
            $this->updateById('events', $id, [
                'publication_status' => 'submitted',
                'rejection_reason' => null,
                'submitted_at' => date(DATE_ATOM),
                'is_test' => 0,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * Submitted -> Published, in one step. The spec models `approved` as a resting ENUM
     * value for future-proofing, but today approval and publication are the same action —
     * there is no separate "approved, not yet published" state, so this never leaves the
     * row at `approved`.
     */
    public function approve(string $id, string $actorId): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['publicationStatus'] !== 'submitted') {
            return null;
        }
        $now = date(DATE_ATOM);
        $this->updateById('events', $id, [
            'publication_status' => 'published',
            'rejection_reason' => null,
            'approved_at' => $now,
            'approved_by' => $actorId,
            'published_at' => $now,
            'published_by' => $actorId,
        ]);
        return $this->find($id);
    }

    /**
     * Submitted -> Draft (with the reason kept for the organizer to see). Like `approved`,
     * `rejected` is a legal ENUM value but not a resting state the application ever writes
     * — rejecting an event is a new Draft cycle, not a persistent "Rejected" status.
     *
     * E5.6: rejection refunds the "pakiet organizatora" credit spent at submit() — the
     * platform decided this Wydarzenie doesn't meet the bar, so it shouldn't cost the
     * organizer money (issue 16 §2). Refund and status change happen in the same
     * transaction as submit()'s consumption does, for the same desync-safety reason.
     * approve() (below) deliberately has no equivalent — consumption is one-shot, at
     * submission time, and approval never touches the credit.
     */
    public function reject(string $id, string $reason): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['publicationStatus'] !== 'submitted') {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->refundOrganizerPackageCredit((string) $event['organizerId']);
            $this->updateById('events', $id, [
                'publication_status' => 'draft',
                'rejection_reason' => $reason,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * Published -> Cancelled. Manual, terminal, irreversible. Bulk refund handling for
     * paid registrations (E1.5, spec 13 rozstrzygnięcie 2) is triggered by the caller
     * (routes.php's `/events/{id}/cancel` handler) via
     * RegistrationRepository::bulkApproveRefundsForEvent() once this returns
     * non-null — this repository has no RegistrationRepository dependency, so it is
     * not done here.
     */
    public function cancel(string $id): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['publicationStatus'] !== 'published') {
            return null;
        }
        $this->updateById('events', $id, ['publication_status' => 'cancelled']);
        return $this->find($id);
    }

    /**
     * The visibility toggle (public/private), only meaningful once the event is
     * Published. `is_private`/`access_code` remain the underlying storage (access_code
     * already existed from an earlier ticket) and stay in sync with `visibility`.
     */
    public function setVisibility(string $id, string $visibility): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['publicationStatus'] !== 'published') {
            return null;
        }
        $visibility = $visibility === 'private' ? 'private' : 'public';
        $this->updateById('events', $id, [
            'visibility' => $visibility,
            'is_private' => (int) ($visibility === 'private'),
        ]);
        return $this->find($id);
    }

    /**
     * is_test is only settable while Draft (E1.1) — it exists purely so an organizer can
     * walk the registration form via a direct link before moderation. submit() clears it
     * irreversibly, so there is no corresponding "set false" escape hatch needed once an
     * event has moved on.
     */
    public function setIsTest(string $id, bool $isTest): ?array
    {
        $event = $this->find($id);
        if (!$event || $event['publicationStatus'] !== 'draft') {
            return null;
        }
        // Irreversible once the event has ever been submitted (E1.1) — a rejected event
        // is Draft again but submitted_at survives the round trip specifically so this
        // check can tell "never submitted" apart from "back in Draft after rejection".
        if (!empty($event['submittedAt'])) {
            return null;
        }
        $this->updateById('events', $id, ['is_test' => (int) $isTest]);
        return $this->find($id);
    }

    /**
     * Published -> Finished for events whose last day has passed (E1.1). PP.18: the scheduler
     * (events.finish_past) is the primary driver; the read paths below still run it as a
     * harmless fallback (one indexed, idempotent UPDATE, no money, no mail) so a missed cron
     * tick never serves a stale "published" past event.
     */
    private function lazilyFinishPastEvents(): void
    {
        $this->finishPastEvents();
    }

    /** PP.18: shared by the read-path fallback and the scheduler job. Returns the number of events finished. */
    public function finishPastEvents(): int
    {
        return (int) $this->pdo->exec(
            "UPDATE events SET publication_status = 'finished'
             WHERE publication_status = 'published'
               AND COALESCE(NULLIF(end_date, ''), date) < CURDATE()"
        );
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
        foreach (['featured', 'isLive', 'isVirtual', 'requireFinalAcceptance', 'isPrivate', 'isTest', 'isExternal', 'registrationUntilEventStart', 'enableMerchandiseInRegistration', 'bzIntegrationEnabled', 'embedEnabled'] as $bool) {
            $event[$bool] = (bool) ($event[$bool] ?? false);
        }
        // E8.11: embed_allowed_origins is nullable at the schema level (same as every
        // other JSON config column here) - a brand-new event, or one created before
        // this ticket, reads back as an empty allowlist rather than null, so callers
        // (the public embed-status endpoint, the organizer settings UI) never have to
        // null-check it.
        $event['embedAllowedOrigins'] = is_array($event['embedAllowedOrigins'] ?? null) ? $event['embedAllowedOrigins'] : [];
        $event['distances'] = array_map(
            fn (array $distance): array => [
                ...$distance,
                'pricingVariants' => $this->children('event_distance_pricing_variants', 'distance_id', (string) $distance['id'], ['priceTiers']),
            ],
            $this->withPaidCounts(
                $event['id'],
                $this->children('event_distances', 'event_id', $event['id'], self::DISTANCE_JSON)
            )
        );
        $event['paymentConfig'] = $this->withPaymentWindowDefaults(
            is_array($event['paymentConfig'] ?? null) ? $event['paymentConfig'] : []
        );
        $event['paymentMethods'] = $this->children('event_payment_methods', 'event_id', $event['id']);
        $event['merchandise'] = $this->children('event_merchandise', 'event_id', $event['id'], ['sizes', 'colors', 'images', 'distanceIds', 'documents']);
        $event['discountCodes'] = $this->children('event_discount_codes', 'event_id', $event['id'], ['distanceIds', 'usedBy']);
        $event['galleryAlbums'] = $this->children('event_gallery_albums', 'event_id', $event['id']);
        $event['resultDocuments'] = $this->children('event_result_documents', 'event_id', $event['id']);
        $event['documents'] = $this->children('event_documents', 'event_id', $event['id']);
        // E1.2 krok 6 (NNW): katalog wariantów jest globalny (InsuranceRepository),
        // organizator wybiera tu, które z nich oferuje na tym wydarzeniu.
        $event['insuranceVariants'] = $this->eventInsuranceVariants($event['id']);

        return $event;
    }

    /**
     * E1.4: applies the two payment-window defaults on top of whatever the organizer
     * has already saved (or nothing at all) — always returns concrete, positive
     * ints so a caller (RegistrationRepository::create(), or the API response the
     * client's settings tab reads back) never has to special-case "not configured
     * yet". Any non-positive or non-numeric override is treated the same as
     * "not set" rather than trusted verbatim, since a zero/negative window would
     * make a Zapis expire before it is even created.
     *
     * @param array<string, mixed> $paymentConfig
     * @return array<string, mixed>
     */
    private function withPaymentWindowDefaults(array $paymentConfig): array
    {
        $gatewayMinutes = (int) ($paymentConfig['gatewayWindowMinutes'] ?? 0);
        $transferDays = (int) ($paymentConfig['transferWindowDays'] ?? 0);

        return [
            'type' => 'admin_gateway',
            // E1.7: which trasa-A gateway (RegistrationRepository::gatewayForProvider())
            // this event's Zapisy pay through — 'tpay' (E1.6, the only provider that
            // existed before this) is the default so every event configured before
            // PayNow existed keeps its exact prior behavior. Irrelevant when
            // `type` is 'organizer_bank' (trasa B has no gateway at all).
            'provider' => 'tpay',
            ...$paymentConfig,
            'gatewayWindowMinutes' => $gatewayMinutes > 0 ? $gatewayMinutes : self::DEFAULT_GATEWAY_WINDOW_MINUTES,
            'transferWindowDays' => $transferDays > 0 ? $transferDays : self::DEFAULT_TRANSFER_WINDOW_DAYS,
        ];
    }

    /**
     * Which NNW catalog variants (InsuranceRepository) this event offers — the
     * organizer's selection from the admin-defined global catalog (E0.3 rozstrzygnięcie
     * 9). Returns full variant objects (not just ids) so the client can render its
     * selection UI without a second round-trip; inactive catalog rows are dropped even
     * if still linked, same as the catalog's own `list()`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventInsuranceVariants(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT iv.id, iv.name, iv.base_fee AS baseFee, iv.age_multiplier AS ageMultiplier, iv.max_age AS maxAge, iv.info_html AS infoHtml
             FROM event_insurance_variants eiv
             INNER JOIN insurance_settings iv ON iv.id = eiv.insurance_variant_id
             WHERE eiv.event_id = :eventId AND iv.active = 1
             ORDER BY eiv.sort_order ASC, iv.sort_order ASC'
        );
        $stmt->execute(['eventId' => $eventId]);
        return array_map(static function (array $row): array {
            $row['baseFee'] = (float) $row['baseFee'];
            $row['ageMultiplier'] = (float) $row['ageMultiplier'];
            $row['maxAge'] = (int) $row['maxAge'];
            return $row;
        }, $stmt->fetchAll());
    }

    /** @param array<int, string> $variantIds */
    private function replaceEventInsuranceVariants(string $eventId, array $variantIds): void
    {
        $this->pdo->prepare('DELETE FROM event_insurance_variants WHERE event_id = :eventId')->execute(['eventId' => $eventId]);
        foreach (array_values($variantIds) as $index => $variantId) {
            $variantId = (string) $variantId;
            if ($variantId === '') {
                continue;
            }
            $this->insert('event_insurance_variants', [
                'event_id' => $eventId,
                'insurance_variant_id' => $variantId,
                'sort_order' => $index,
            ]);
        }
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

        $stmt = $this->pdo->prepare("SELECT r.distance_id AS distanceId, COUNT(*) AS cnt FROM registrations r WHERE r.event_id = :eventId AND r.status NOT IN ('removed', 'cancelled', 'expired') GROUP BY r.distance_id");
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
            $registeredCount = $registered[$id] ?? 0;
            $distance['registeredCount'] = $registeredCount;
            $distance['paidCount'] = $paid[$id] ?? 0;
            // E8.2 (spec 21 Answer §4): free-slots counter, computed alongside the
            // existing live registeredCount above (no new materialized state — the
            // one exception, event_distances.registered_count, is E1.3's separate
            // transactional capacity counter and is not what this reads). `null`
            // limit means unlimited, so there is no "remaining" to compute.
            // Whether this ever reaches an anonymous reader is decided by
            // routes.php's $stripBzToken (renamed scope, see its own doc comment)
            // per the Event's `display_options.showFreeSlots` toggle — manager
            // reads always keep it, same as every other field that function guards.
            $limit = $distance['limit'] ?? null;
            $distance['slotsRemaining'] = $limit !== null ? max(0, (int) $limit - $registeredCount) : null;
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
            'publicationStatus' => 'publication_status',
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
            'organizersHtml' => 'organizers_html',
            'rulesHtml' => 'rules_html',
            'insuranceOption' => 'insurance_option',
            'bzIntegrationEnabled' => 'bz_integration_enabled',
            'bzOrganizationToken' => 'bz_organization_token',
            'enableMerchandiseInRegistration' => 'enable_merchandise_in_registration',
            'finalAcceptanceHtml' => 'final_acceptance_html',
            'requireFinalAcceptance' => 'require_final_acceptance',
            'isPrivate' => 'is_private',
            'visibility' => 'visibility',
            'isTest' => 'is_test',
            'isExternal' => 'is_external',
            'accessCode' => 'access_code',
            'registrationEmbed' => 'registration_embed',
            'registrationFormConfig' => 'registration_form_config',
            'embedEnabled' => 'embed_enabled',
            'embedAllowedOrigins' => 'embed_allowed_origins',
            'displayOptions' => 'display_options',
            'logisticsInfo' => 'logistics_info',
            'backgroundId' => 'background_id',
        ];
        $out = $creating ? ['id' => $id, 'publication_status' => 'draft', 'featured' => 0] : [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $value = is_bool($data[$input]) ? (int) $data[$input] : $data[$input];
                $out[$column] = $input === 'publicationStatus' ? $this->normalizeStatus((string) $value) : $value;
            }
        }
        // `status` is the pre-E1.1 input key (still used by older callers/fixtures);
        // `publicationStatus` is the canonical one and wins if both are present.
        if (array_key_exists('status', $data) && !array_key_exists('publicationStatus', $data)) {
            $out['publication_status'] = $this->normalizeStatus((string) $data['status']);
        }
        // visibility (E1.1) and the legacy is_private boolean describe the same axis —
        // keep them in sync however the caller supplied it, so old and new consumers
        // both see a consistent value.
        if (array_key_exists('visibility', $data) && !array_key_exists('isPrivate', $data)) {
            $out['is_private'] = (int) ($data['visibility'] === 'private');
        } elseif (array_key_exists('isPrivate', $data) && !array_key_exists('visibility', $data)) {
            $out['visibility'] = !empty($data['isPrivate']) ? 'private' : 'public';
        }
        foreach (self::RICH_TEXT_COLUMNS as $column) {
            if (array_key_exists($column, $out)) {
                $out[$column] = HtmlSanitizer::cleanNullable($out[$column]);
            }
        }
        if (array_key_exists('logistics_info', $out)) {
            $out['logistics_info'] = $this->sanitizeLogisticsInfo($out['logistics_info']);
        }
        foreach (['organizer_contact', 'banner_crop_area', 'payment_config', 'registration_embed', 'registration_form_config', 'embed_allowed_origins', 'display_options', 'logistics_info', 'organizer_info', 'insurance_option'] as $jsonField) {
            if (array_key_exists($jsonField, $out) && $out[$jsonField] !== null && !is_string($out[$jsonField])) {
                $out[$jsonField] = json_encode($out[$jsonField], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $out;
    }

    /**
     * Cleans LogisticsField.infoHtml for every key of the logistics_info JSON
     * (array or already-encoded string).
     */
    private function sanitizeLogisticsInfo(mixed $value): mixed
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return $value;
        }
        foreach ($decoded as $key => $field) {
            if (is_array($field) && array_key_exists('infoHtml', $field)) {
                $decoded[$key]['infoHtml'] = HtmlSanitizer::cleanNullable($field['infoHtml']);
            }
        }
        return $decoded; // the existing json_encode loop re-encodes it
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

    /**
     * Accepts both the new canonical values and every historical spelling this column
     * has ever had (pre-E1.1 `active`/`past`/`archived`/`awaiting_approval`, and the even
     * older `published`/`completed`/`cancelled` aliases from 001_init.sql), so old
     * fixtures/payloads keep working without a data migration of every call site.
     *
     * `approved` and `rejected` are legal ENUM values (schema future-proofing per spec)
     * but are never the *result* of normalizing an action — approve()/reject() below
     * write `published`/`draft` directly and never call this with those verbs. A caller
     * handing this method the literal string 'approved' or 'rejected' still gets it back
     * unchanged, since both are in PUBLICATION_STATUSES; they just never arise from the
     * alias table.
     */
    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'active' => 'published',
            'past', 'archived', 'completed' => 'finished',
            'awaiting_approval' => 'submitted',
            'deleted' => 'draft',
            default => in_array($status, self::PUBLICATION_STATUSES, true) ? $status : 'draft',
        };
    }

    private function activeEventCount(string $organizerId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM events WHERE organizer_id = :organizerId AND deleted_at IS NULL');
        $stmt->execute(['organizerId' => $organizerId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $event */
    public function canDeleteBeforeRegistrationOpened(array $event): bool
    {
        if (in_array((string) ($event['publicationStatus'] ?? ''), ['finished', 'cancelled'], true) || $event['deletedAt'] !== null) {
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

    private function assertOrganizerHasCapacity(string $organizerId): void
    {
        $limit = $this->eventLimit($organizerId);
        if ($limit['used'] >= $limit['limit']) {
            throw new \RuntimeException('event_limit_exceeded');
        }
    }

    /**
     * E5.6: atomic, affected-rows-guarded decrement of the "pakiet organizatora"
     * credit — mirrors incrementRegisteredCount()'s pattern in
     * RegistrationRepository (E1.3): one UPDATE whose WHERE clause is the check,
     * so there is no separate read-then-write race window. Deliberately its own
     * counter (`users.organizer_package_credits`, migration 053) rather than the
     * pre-existing but unused organizer_event_credit_batches/usages tables above
     * (see 053's own comment for why) or `event_limit` (a different axis).
     */
    private function consumeOrganizerPackageCredit(string $organizerId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET organizer_package_credits = organizer_package_credits - 1
             WHERE id = :organizerId AND organizer_package_credits > 0'
        );
        $stmt->execute(['organizerId' => $organizerId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('event_submit_no_organizer_credit');
        }
    }

    /** @see self::consumeOrganizerPackageCredit — the refund half, on reject(). */
    private function refundOrganizerPackageCredit(string $organizerId): void
    {
        $this->pdo->prepare(
            'UPDATE users SET organizer_package_credits = organizer_package_credits + 1 WHERE id = :organizerId'
        )->execute(['organizerId' => $organizerId]);
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

    /**
     * PP.59: validate and normalize every child collection present in the request, with no database
     * access, so a bad value rejects the whole request before the first write. Column names come only
     * from EventChildSchema constants; client keys are only looked up there.
     *
     * @param array<string, mixed> $data
     * @return array{collections: array<string, list<array<string, mixed>>>, unknown: array<string, list<string>>, insurance: list<string>|null}
     */
    private function prepareChildren(array $data, bool $trusted): array
    {
        $collections = [];
        $unknown = [];

        foreach (array_keys(EventChildSchema::COLLECTIONS) as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $items = $this->listOrFail($data[$key], $key, EventChildSchema::COLLECTION_LABELS[$key]);
            $prepared = [];
            foreach ($items as $i => $item) {
                $path = $key . '[' . $i . ']';
                if (!is_array($item)) {
                    throw InputRules::invalid($path, EventChildSchema::COLLECTION_LABELS[$key]);
                }
                $normalized = EventChildSchema::normalize($key, $item, $path, $trusted);
                $this->collectUnknown($unknown, $key, $normalized['unknownKeys']);
                $entry = $this->childEntry($item, $normalized['columns'], $path, $trusted);
                if ($key === 'distances' && array_key_exists('pricingVariants', $item)) {
                    $entry['variants'] = [];
                    $variantItems = $this->listOrFail(
                        $item['pricingVariants'],
                        $path . '.pricingVariants',
                        EventChildSchema::COLLECTION_LABELS[EventChildSchema::PRICING_VARIANTS]
                    );
                    foreach ($variantItems as $j => $variant) {
                        $variantPath = $path . '.pricingVariants[' . $j . ']';
                        if (!is_array($variant)) {
                            throw InputRules::invalid($variantPath, EventChildSchema::COLLECTION_LABELS[EventChildSchema::PRICING_VARIANTS]);
                        }
                        $normalizedVariant = EventChildSchema::normalize(EventChildSchema::PRICING_VARIANTS, $variant, $variantPath, $trusted);
                        $this->collectUnknown($unknown, EventChildSchema::PRICING_VARIANTS, $normalizedVariant['unknownKeys']);
                        $entry['variants'][] = $this->childEntry($variant, $normalizedVariant['columns'], $variantPath, $trusted);
                    }
                }
                $prepared[] = $entry;
            }
            $collections[$key] = $prepared;
        }

        $insurance = null;
        if (array_key_exists('insuranceVariantIds', $data)) {
            $insurance = [];
            foreach ($this->listOrFail($data['insuranceVariantIds'], 'insuranceVariantIds', 'Warianty NNW') as $variantId) {
                if (!is_string($variantId) || trim($variantId) === '' || mb_strlen($variantId) > 36) {
                    throw InputRules::invalid('insuranceVariantIds', 'Warianty NNW');
                }
                $insurance[] = $variantId;
            }
            $insurance = array_values(array_unique($insurance));
        }

        return ['collections' => $collections, 'unknown' => $unknown, 'insurance' => $insurance];
    }

    /** @return array<mixed> null clears the collection (as before); anything but a list is a 422 */
    private function listOrFail(mixed $value, string $field, string $label): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw InputRules::invalid($field, $label);
        }

        return $value;
    }

    /**
     * @param array<mixed> $item
     * @param array<string, mixed> $columns
     * @return array{rawId: string|null, suppliedId: string|null, columns: array<string, mixed>, variants: list<array<string, mixed>>|null}
     */
    private function childEntry(array $item, array $columns, string $path, bool $trusted): array
    {
        $rawId = isset($item['id']) && (is_string($item['id']) || is_int($item['id'])) ? (string) $item['id'] : null;
        $suppliedId = null;
        if ($trusted && $rawId !== null && trim($rawId) !== '') {
            if (mb_strlen($rawId) > 36) {
                throw new InputValidationException('Pole „Identyfikator” może mieć najwyżej 36 znaków.', $path . '.id');
            }
            $suppliedId = $rawId;
        } elseif ($trusted && isset($item['id']) && $rawId === null) {
            throw InputRules::invalid($path . '.id', 'Identyfikator');
        }

        // $rawId is only ever used to MATCH an existing row of this event; it is never written
        // unless the caller is trusted (seeder / dev seed route) and the id fits the column.
        return ['rawId' => $rawId, 'suppliedId' => $suppliedId, 'columns' => $columns, 'variants' => null];
    }

    /**
     * @param array<string, list<string>> $unknown
     * @param list<string> $keys
     */
    private function collectUnknown(array &$unknown, string $collection, array $keys): void
    {
        foreach ($keys as $key) {
            $clean = substr((string) preg_replace('/[^\x20-\x7E]/', '?', $key), 0, 64);
            if (!in_array($clean, $unknown[$collection] ?? [], true) && count($unknown[$collection] ?? []) < 20) {
                $unknown[$collection][] = $clean;
            }
        }
    }

    /**
     * PP.59: diff-sync the prepared children inside the caller's transaction: rows whose id already
     * belongs to THIS event are updated in place (counters and FK children survive), everything else
     * becomes a new row with a server-generated id, and rows missing from the request are deleted.
     *
     * @param array{collections: array<string, list<array<string, mixed>>>, unknown: array<string, list<string>>, insurance: list<string>|null} $prepared
     */
    private function syncChildren(string $eventId, array $prepared): void
    {
        $distanceIdMap = [];

        foreach ($prepared['collections'] as $collection => $items) {
            $table = EventChildSchema::COLLECTIONS[$collection];
            $existing = $this->existingChildIds($table, 'event_id', $eventId);
            $claimed = [];
            $variantWork = [];

            foreach ($items as $index => $item) {
                $columns = $item['columns'];
                if ($collection === 'merchandise' || $collection === 'discountCodes') {
                    $columns['distance_ids'] = $this->remapDistanceIds($columns['distance_ids'] ?? null, $distanceIdMap);
                }
                $rawId = $item['rawId'];
                if ($rawId !== null && isset($existing[$rawId]) && !isset($claimed[$rawId])) {
                    $claimed[$rawId] = true;
                    $id = $rawId;
                    $this->updateChild($table, 'event_id', $eventId, $id, $columns, $index);
                } else {
                    $id = $item['suppliedId'] ?? $this->id();
                    $this->insertChild($table, 'event_id', $eventId, $id, $columns, $index);
                    if ($collection === 'distances' && $rawId !== null && $rawId !== $id) {
                        $distanceIdMap[$rawId] = $id;
                    }
                }
                if ($collection === 'distances' && $item['variants'] !== null) {
                    $variantWork[$id] = $item['variants'];
                }
            }

            foreach (array_keys($existing) as $oldId) {
                if (isset($claimed[$oldId])) {
                    continue;
                }
                if ($collection === 'distances') {
                    $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id = ?')->execute([(string) $oldId]);
                }
                $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ? AND event_id = ?')->execute([(string) $oldId, $eventId]);
            }

            foreach ($variantWork as $distanceId => $variants) {
                $this->syncPricingVariants((string) $distanceId, $variants);
            }
        }

        if ($prepared['insurance'] !== null) {
            $this->replaceEventInsuranceVariants($eventId, $prepared['insurance']);
        }

        foreach ($prepared['unknown'] as $collection => $keys) {
            // Key NAMES only, never values: a request may carry personal data in those values.
            $this->logger->notice('event_child_unknown_keys_dropped', [
                'eventId' => $eventId,
                'collection' => $collection,
                'keys' => $keys,
            ]);
        }
    }

    /**
     * Wariant opłaty (E0.3): same diff sync as the other children, scoped by distance_id.
     *
     * @param list<array<string, mixed>> $variants
     */
    private function syncPricingVariants(string $distanceId, array $variants): void
    {
        $table = EventChildSchema::PRICING_VARIANTS_TABLE;
        $existing = $this->existingChildIds($table, 'distance_id', $distanceId);
        $claimed = [];
        foreach ($variants as $index => $variant) {
            $rawId = $variant['rawId'];
            if ($rawId !== null && isset($existing[$rawId]) && !isset($claimed[$rawId])) {
                $claimed[$rawId] = true;
                $this->updateChild($table, 'distance_id', $distanceId, $rawId, $variant['columns'], $index);
            } else {
                $this->insertChild($table, 'distance_id', $distanceId, $variant['suppliedId'] ?? $this->id(), $variant['columns'], $index);
            }
        }
        foreach (array_keys($existing) as $oldId) {
            if (!isset($claimed[$oldId])) {
                $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ? AND distance_id = ?')->execute([(string) $oldId, $distanceId]);
            }
        }
    }

    /** @return array<string, true> */
    private function existingChildIds(string $table, string $ownerColumn, string $ownerId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ' . $table . ' WHERE ' . $ownerColumn . ' = ?');
        $stmt->execute([$ownerId]);
        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $ids[(string) $id] = true;
        }

        return $ids;
    }

    /**
     * Table and column names reaching insertChild()/updateChild() come from EventChildSchema and
     * this class's constants only; every value is bound positionally.
     *
     * @param array<string, mixed> $columns
     */
    private function insertChild(string $table, string $ownerColumn, string $ownerId, string $id, array $columns, int $sortOrder): void
    {
        $columns = ['id' => $id, $ownerColumn => $ownerId] + $columns + ['sort_order' => $sortOrder];
        $sql = 'INSERT INTO ' . $table
            . ' (' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($columns))) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($columns));
    }

    /** @param array<string, mixed> $columns */
    private function updateChild(string $table, string $ownerColumn, string $ownerId, string $id, array $columns, int $sortOrder): void
    {
        $columns += ['sort_order' => $sortOrder];
        $sql = 'UPDATE ' . $table . ' SET '
            . implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($columns)))
            . ' WHERE id = ? AND ' . $ownerColumn . ' = ?';
        $this->pdo->prepare($sql)->execute([...array_values($columns), $id, $ownerId]);
    }

    /** @param array<string, string> $map temporary client distance id => server distance id */
    private function remapDistanceIds(mixed $json, array $map): mixed
    {
        if (!is_string($json) || $map === []) {
            return $json;
        }
        $ids = json_decode($json, true);
        if (!is_array($ids)) {
            return $json;
        }
        foreach ($ids as $key => $id) {
            if (is_string($id) && isset($map[$id])) {
                $ids[$key] = $map[$id];
            }
        }

        return json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Runs $work inside a transaction unless the caller already opened one (the seeder and some
     * tests do; PDO does not support nesting).
     */
    private function inOwnTransaction(callable $work): mixed
    {
        $own = !$this->transactionOpen();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $work();
            if ($own) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            // a failed statement may already have ended the transaction (e.g. a DDL implicit commit)
            if ($own && $this->transactionOpen()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @phpstan-impure the answer changes when the transaction ends */
    private function transactionOpen(): bool
    {
        return $this->pdo->inTransaction();
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
}
