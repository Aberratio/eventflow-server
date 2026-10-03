<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use Psr\Log\LoggerInterface;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxService;
use ZmierzymyCzas\Domain\Competitors\CompetitorNamePresenter;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldCatalog;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Payments\GatewayAmount;
use ZmierzymyCzas\Domain\Payments\PaymentGatewayInterface;
use ZmierzymyCzas\Domain\Repository;
use ZmierzymyCzas\Domain\Teams\TeamRepository;
use ZmierzymyCzas\Support\Log\LoggerFactory;
use ZmierzymyCzas\Support\QrCode;

final class RegistrationRepository extends Repository
{
    /**
     * Trasa A (bramka) seam (E1.6/E1.7) — null in every environment without real
     * gateway credentials configured (local dev, CI, this repo's own test suite),
     * in which case create()/retryPayment() simply don't start a payment session
     * (see startGatewaySessionIfNeeded()) rather than attempting a call that could
     * only ever fail. Production wiring lives in routes.php.
     *
     * Two independent gateways can be wired in — Tpay (`$gateway`, the default
     * provider, E1.6) and PayNow (`$payNowGateway`, E1.7) — both implementing
     * the exact same PaymentGatewayInterface, so nothing below branches on
     * *behavior* between them; the only place either name appears is
     * gatewayForProvider(), which just picks which already-built instance a
     * given Wydarzenie's payment_config.provider selects. See
     * paymentGatewayProviderFor().
     */
    private readonly ?PaymentGatewayInterface $gateway;
    private readonly ?PaymentGatewayInterface $payNowGateway;

    /**
     * E1.10 seam — mirrors $gateway/$payNowGateway above: null whenever Central
     * Mailer isn't configured (CriticalMailer's own CentralMailerConfig::isConfigured()
     * gate handles that), in which case every send*() call below is a silent
     * no-op rather than an attempted call that could only ever fail.
     */
    private readonly ?CriticalMailer $mailer;

    /**
     * E3.4 seam — mirrors $mailer above: null whenever this deployment has no
     * BZ_WEBHOOK_BASE_URL configured at all (local dev, CI, this repo's own
     * test suite), in which case pushBzCriticalChange() is a silent no-op
     * before it ever touches an Event row, rather than an attempted call that
     * could only ever fail. A non-null instance here does not by itself mean
     * every Event gets pushed to — pushBzCriticalChange() additionally checks
     * that specific Event's own bz_integration_enabled + bz_organization_token
     * (E3.2) before enqueueing anything.
     */
    private readonly ?BzWebhookOutboxService $bzWebhookOutbox;

    /** PP.20: PSR-3 logger; defaults to the error_log-backed fallback so nothing is ever silent. */
    private readonly LoggerInterface $logger;

    public function __construct(
        \PDO $pdo,
        ?PaymentGatewayInterface $gateway = null,
        ?PaymentGatewayInterface $payNowGateway = null,
        ?CriticalMailer $mailer = null,
        ?BzWebhookOutboxService $bzWebhookOutbox = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($pdo);
        $this->logger = $logger ?? LoggerFactory::fallback();
        $this->gateway = $gateway;
        $this->payNowGateway = $payNowGateway;
        $this->mailer = $mailer;
        $this->bzWebhookOutbox = $bzWebhookOutbox;
    }

    /** Provider name for the default gateway constructor argument ($gateway) — Tpay, E1.6. */
    private const PROVIDER_TPAY = 'tpay';
    /** Provider name for the second gateway constructor argument ($payNowGateway) — PayNow, E1.7. */
    private const PROVIDER_PAYNOW = 'paynow';
    /**
     * Gateways whose paid notification must carry an amount (PP.6): a missing or
     * unparseable amount from one of these is a mismatch. PayNow is deliberately
     * absent — its notification has no amount field (see PayNowNotification).
     */
    private const AMOUNT_REPORTING_GATEWAYS = [self::PROVIDER_TPAY];

    /**
     * E3.5: BZ-return-syncable identity fieldKeys living on `competitors` —
     * fieldKey (the same vocabulary bzParticipantList()/FormFieldCatalog use)
     * => the CompetitorRepository::update() input key it maps to.
     */
    private const BZ_SYNCABLE_COMPETITOR_FIELDS = ['first_name' => 'firstName', 'last_name' => 'lastName'];

    /**
     * E3.5/E3.6: BZ-return-syncable fieldKeys living directly on
     * `registrations` — fieldKey => the camelCase key bzSyncRow() aliases the
     * column to. Written back to their real snake_case column by
     * applyBzFieldSync() (identical to the fieldKey itself for
     * category/bib_number/checked_in_at/checked_in_by; contact_email maps to
     * the user_email column, same "system field key not sharing the column's
     * own name" case as bzParticipantList()'s own contact_email handling).
     *
     * checked_in_at/checked_in_by (E3.6, issue 12 §4, "BZ zwraca status
     * odprawy przy każdej synchronizacji") were added here rather than given
     * a separate endpoint/mechanism: they are return-synced fields on this
     * same `registrations` row, so they fit this exact contract (LWW per
     * fieldKey against `registrations.updated_at`) with zero new plumbing.
     */
    private const BZ_SYNCABLE_REGISTRATION_FIELDS = [
        'category' => 'category',
        'bib_number' => 'bibNumber',
        'contact_email' => 'userEmail',
        'checked_in_at' => 'checkedInAt',
        'checked_in_by' => 'checkedInBy',
    ];

    /**
     * Which already-constructed gateway instance a provider name resolves to.
     * The only place in this class that ever mentions "tpay"/"paynow" by
     * name — everywhere else just calls PaymentGatewayInterface methods on
     * whatever this returns, so business logic (amount, idempotency, refund
     * flow) is identical regardless of which provider a Wydarzenie picked.
     */
    private function gatewayForProvider(string $provider): ?PaymentGatewayInterface
    {
        return $provider === self::PROVIDER_PAYNOW ? $this->payNowGateway : $this->gateway;
    }

    /**
     * `payment_config.provider` on the Wydarzenie — which gateway trasa A uses,
     * defaulting to Tpay (E1.6, the only provider that existed before E1.7) when
     * unset, so every event configured before PayNow existed keeps behaving
     * exactly as it did.
     */
    private function paymentGatewayProviderFor(string $eventId): string
    {
        $stmt = $this->pdo->prepare('SELECT payment_config AS paymentConfig FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $raw = $stmt->fetchColumn();
        $paymentConfig = is_string($raw) && trim((string) $raw) !== '' ? (json_decode((string) $raw, true) ?: []) : [];
        $provider = is_array($paymentConfig) ? (string) ($paymentConfig['provider'] ?? '') : '';

        return $provider === self::PROVIDER_PAYNOW ? self::PROVIDER_PAYNOW : self::PROVIDER_TPAY;
    }

    private const COLUMNS = <<<'SQL'
id, event_id AS eventId, event_name AS eventName, user_id AS userId, competitor_id AS competitorId,
user_name AS userName,
user_email AS userEmail, distance_id AS distanceId, distance_name AS distanceName, category,
registered_at AS registeredAt, status, bib_number AS bibNumber, qr_code AS qrCode, team_name AS teamName, team_id AS teamId,
discount_code AS discountCode, removed_at AS removedAt, checked_in_at AS checkedInAt, checked_in_by AS checkedInBy,
registered_by_id AS registeredById
SQL;

    private const COLUMNS_R = <<<'SQL'
r.id, r.event_id AS eventId, r.event_name AS eventName, r.user_id AS userId, r.competitor_id AS competitorId,
r.user_name AS userName,
r.user_email AS userEmail, r.distance_id AS distanceId, r.distance_name AS distanceName, r.category,
r.registered_at AS registeredAt, r.status, r.bib_number AS bibNumber, r.qr_code AS qrCode, r.team_name AS teamName, r.team_id AS teamId,
r.discount_code AS discountCode, r.removed_at AS removedAt, r.checked_in_at AS checkedInAt, r.checked_in_by AS checkedInBy,
r.registered_by_id AS registeredById
SQL;

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = []): array
    {
        $this->lazilyExpireUnpaidRegistrations();
        $where = [];
        $params = [];
        foreach (['eventId' => 'event_id', 'userId' => 'user_id', 'status' => 'status'] as $input => $column) {
            if (!empty($filters[$input])) {
                $where[] = "{$column} = :{$input}";
                $params[$input] = $filters[$input];
            }
        }
        if (!empty($filters['search'])) {
            $where[] = '(user_name LIKE :search OR user_email LIKE :search OR bib_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagination = $this->pagination($filters);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM registrations{$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM registrations{$whereSql} ORDER BY registered_at DESC LIMIT :limit OFFSET :offset");
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

    /** @return array<int, array<string, mixed>> */
    public function byEvent(string $eventId): array
    {
        $this->lazilyExpireUnpaidRegistrations();
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM registrations WHERE event_id = :eventId ORDER BY registered_at DESC');
        $stmt->execute(['eventId' => $eventId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * PP.3: the exact — and only — keys a public, unauthenticated caller ever sees for
     * one start-list entry. Never `hydrate()`d: that method runs four extra queries per
     * row and attaches financial/PII fields (userEmail, qrCode, discountCode,
     * checkedInBy, merchandiseOrders with prices, paymentDetails, settlementItems…)
     * that must never reach this response. Order is pinned by
     * PublicStartListContractTest.
     *
     * @var list<string>
     */
    public const PUBLIC_START_LIST_FIELDS = [
        'id', 'distanceId', 'distanceName', 'category', 'userId', 'userName', 'bibNumber',
        'teamName', 'teamId', 'status', 'registeredAt', 'hasMerchandise', 'formFields',
    ];

    /** @return array<int, array<string, mixed>> */
    public function publicStartList(string $eventId, bool $showAll): array
    {
        $this->lazilyExpireUnpaidRegistrations();
        $hasMerchandiseExpr = 'EXISTS (SELECT 1 FROM registration_merchandise_orders mo WHERE mo.registration_id = %s) AS hasMerchandise';
        if ($showAll) {
            // Qualified `registrations.id`: an unqualified `id` here would resolve to
            // registration_merchandise_orders.id (its own PK) inside the correlated
            // subquery's scope, not the outer row — always false.
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLUMNS . ', ' . sprintf($hasMerchandiseExpr, 'registrations.id')
                . " FROM registrations WHERE event_id = :eventId AND status NOT IN ('removed', 'cancelled', 'expired') ORDER BY registered_at DESC"
            );
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLUMNS_R . ', ' . sprintf($hasMerchandiseExpr, 'r.id')
                . " FROM registrations r LEFT JOIN registration_payment_details pd ON pd.registration_id = r.id WHERE r.event_id = :eventId AND r.status NOT IN ('removed', 'cancelled', 'expired') AND (r.status = 'confirmed' OR pd.start_fee_paid = 1) ORDER BY r.registered_at DESC"
            );
        }
        $stmt->execute(['eventId' => $eventId]);
        $rows = $stmt->fetchAll();

        $withFormFields = $this->attachPublicFormFields($eventId, $rows);
        $masked = $this->maskAnonymizedCompetitors($withFormFields);

        return array_map($this->toPublicStartListEntry(...), $masked);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublicStartListEntry(array $row): array
    {
        $anonymized = (bool) ($row['__anonymized'] ?? false);
        $formFields = array_values(array_filter(
            (array) ($row['formFields'] ?? []),
            static fn (array $f): bool => ($f['fieldKey'] ?? '') !== 'contact_email'
        ));

        return [
            'id' => (string) $row['id'],
            'distanceId' => $row['distanceId'],
            'distanceName' => $row['distanceName'],
            'category' => $row['category'],
            'userId' => $anonymized ? null : ($row['userId'] ?? null),
            'userName' => $row['userName'],
            'bibNumber' => $row['bibNumber'],
            'teamName' => $row['teamName'],
            'teamId' => $row['teamId'],
            'status' => $row['status'],
            'registeredAt' => $row['registeredAt'],
            'hasMerchandise' => (bool) $row['hasMerchandise'],
            'formFields' => $formFields,
        ];
    }

    /**
     * TD.6 (E6.6 follow-up, "wire anonymization masking into public
     * start-list and results"): the public start list is a second place a
     * competitor's real name reaches an unauthenticated caller — E6.6 wired
     * `competitors.is_anonymized` into the Wyzwanie/Series ranking only (see
     * CompetitorNamePresenter's own docblock). Masks both places a name
     * actually surfaces in this response:
     *  - the flat `userName` snapshot (`registrations.user_name`, frozen at
     *    registration time and never re-derived from the live Competitor
     *    otherwise);
     *  - the `first_name`/`last_name` entries attachPublicFormFields()
     *    resolves into `formFields` (live or frozen, see below).
     *
     * Deliberately a POST-PROCESSING step over attachPublicFormFields()'s
     * output, not a parameter threaded through resolveLiveFormFields()/
     * attachFrozenFormFields()/resolvePublicFieldValue() — those three are
     * also shared by bzParticipantList() (BZ integration, its own
     * API-key + org-token gate) and startListExportRows() (organizer export,
     * $canManageEvent-gated), both staff/organizer-facing paths that must
     * keep seeing real names. attachPublicFormFields() itself has exactly one
     * caller (this method), so masking here can never leak into either of
     * those two.
     *
     * Always reflects the competitor's CURRENT is_anonymized flag, even for a
     * finished Event serving a pre-frozen formFields snapshot — the toggle is
     * a live self-service action (routes.php's POST
     * /competitors/{id}/anonymization), so a competitor flipping it after
     * their Event finished still expects their name masked on every future
     * read, not only a value that happened to be frozen before they opted in.
     *
     * @param array<int, array<string, mixed>> $registrations
     * @return array<int, array<string, mixed>>
     */
    private function maskAnonymizedCompetitors(array $registrations): array
    {
        if ($registrations === []) {
            return $registrations;
        }

        $competitorRepository = new CompetitorRepository($this->pdo);
        $competitorCache = [];

        return array_map(function (array $registration) use ($competitorRepository, &$competitorCache): array {
            $competitorId = $registration['competitorId'] ?? null;
            if ($competitorId === null) {
                return $registration;
            }
            if (!array_key_exists($competitorId, $competitorCache)) {
                $competitorCache[$competitorId] = $competitorRepository->find((string) $competitorId);
            }
            $competitor = $competitorCache[$competitorId];
            if ($competitor === null || !((bool) ($competitor['isAnonymized'] ?? false))) {
                return $registration;
            }

            $registration['userName'] = CompetitorNamePresenter::publicName((string) $registration['userName'], true);
            // PP.3: internal marker consumed only by toPublicStartListEntry() (this
            // method's only caller, via publicStartList()) to null out `userId` —
            // never surfaces itself, since the DTO projection never copies it.
            $registration['__anonymized'] = true;

            if (isset($registration['formFields']) && is_array($registration['formFields'])) {
                $registration['formFields'] = array_map(static function (array $field): array {
                    if ($field['fieldKey'] === 'first_name') {
                        $field['value'] = CompetitorNamePresenter::ANONYMIZED_LABEL;
                    } elseif ($field['fieldKey'] === 'last_name') {
                        // Both halves masked into one label rather than
                        // duplicating it on both fields (see this method's
                        // own docblock's format reasoning, mirroring
                        // CompetitorNamePresenter's own single-label choice).
                        $field['value'] = null;
                    }
                    return $field;
                }, $registration['formFields']);
            }

            return $registration;
        }, $registrations);
    }

    /**
     * E3.3: "Pull uczestników" — the payload Biuro Zawodów's own polling reads
     * per Wydarzenie (issue 12 §1/§5). Deliberately hand-built rather than
     * reusing hydrate()'s full row: hydrate() attaches settlementItems/
     * pricePaid/discountAmount/insurancePaid/totalAmount and merchandise
     * lines carry `price` — exactly the financial detail this endpoint must
     * never expose ("status opłaty (opłacony/nie — bez kwot, BZ nie
     * potrzebuje Rozliczenia)"). Whitelisting the response shape explicitly
     * here, instead of blacklisting fields off of hydrate()'s output, means a
     * future money field added to hydrate() can never leak into this payload
     * by accident.
     *
     * Scope: every non-removed/cancelled/expired Zapis, mirroring
     * publicStartList()'s $showAll=true branch — BZ needs pending/unpaid
     * entries too (an on-site "biuro zawodów" registration on race day, ticket
     * 07 pkt 13, is unpaid until checked in) since payment status here is a
     * per-record boolean, not a pre-filter.
     *
     * Identity fields (imię/nazwisko/e-mail) are the CONTROL_LOCKED system
     * positions (FormFieldCatalog) — always required, so always resolved here
     * unconditionally, the same way they are not gated by any visibility flag
     * on the public start list either. Custom fields are gated by
     * FormFieldRepository::bzVisibleColumns() (`visible_to_bz`, issue 12 §5) —
     * a deliberate subset of formularza z ticketu 10, not everything. Both
     * groups are resolved through the exact same live/frozen fork
     * (resolveLiveFormFields()/attachFrozenFormFields()) the public start list
     * already uses, so a finished Event serves its frozen snapshot here too
     * rather than re-deriving live values that may have since changed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bzParticipantList(string $eventId): array
    {
        $this->lazilyExpireUnpaidRegistrations();

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM registrations WHERE event_id = :eventId AND status NOT IN ('removed', 'cancelled', 'expired') ORDER BY registered_at ASC"
        );
        $stmt->execute(['eventId' => $eventId]);
        $registrations = $stmt->fetchAll();

        if ($registrations === []) {
            return [];
        }

        // ensureSystemCatalog() rather than listForEvent(): an Event created
        // after migration 041 has no guarantee anything has seeded its 13
        // system positions yet (see FormFieldRepository's own routes.php
        // comment on this — publicStartList()'s equivalent lookup has this
        // same latent gap, pre-existing and out of scope here). The identity
        // section going missing from a BZ pull is a hard requirement, so this
        // endpoint defends itself rather than inheriting that gap.
        $formFieldRepository = new FormFieldRepository($this->pdo);
        $identityKeys = ['first_name', 'last_name', 'contact_email'];
        $columns = array_values(array_filter(
            $formFieldRepository->ensureSystemCatalog($eventId),
            static fn (array $field): bool => in_array($field['fieldKey'], $identityKeys, true)
        ));
        $columns = [...$columns, ...$formFieldRepository->bzVisibleColumns($eventId)];

        $withFormFields = $this->eventIsFinished($eventId)
            ? $this->attachFrozenFormFields($registrations, $columns)
            : $this->resolveLiveFormFields($registrations, $columns);

        $competitorRepository = new CompetitorRepository($this->pdo);
        $competitorCache = [];

        return array_map(function (array $registration) use ($competitorRepository, &$competitorCache): array {
            $competitorId = $registration['competitorId'] ?? null;
            $identityCode = null;
            if ($competitorId !== null) {
                if (!array_key_exists($competitorId, $competitorCache)) {
                    $competitorCache[$competitorId] = $competitorRepository->find((string) $competitorId);
                }
                $identityCode = $competitorCache[$competitorId]['identityCode'] ?? null;
            }

            $paymentDetails = $this->paymentDetails((string) $registration['id']);
            $paid = (bool) ($paymentDetails['startFeePaid'] ?? false);

            $merchandise = array_map(
                static fn (array $line): array => [
                    'item' => $line['merchandiseName'],
                    'size' => $line['size'],
                    'quantity' => (int) $line['quantity'],
                ],
                $this->merchandise((string) $registration['id'])
            );

            return [
                'registrationId' => $registration['id'],
                'distanceName' => $registration['distanceName'],
                'category' => $registration['category'],
                'bibNumber' => $registration['bibNumber'],
                'qrCode' => $registration['qrCode'],
                'identityCode' => $identityCode,
                'paid' => $paid,
                'merchandise' => $merchandise,
                'formFields' => $registration['formFields'],
            ];
        }, $withFormFields);
    }

    /**
     * E3.5 (issue 12 §2): "Last-write-wins po znaczniku czasu" — the inbound
     * half of the BZ sync contract, mirror image of bzParticipantList()
     * above. BZ pushes locally-edited fields back with a per-field edit
     * timestamp (`changedAt`); this compares that timestamp against the
     * platform's own last-touch timestamp for the same field/row and applies
     * the edit only when BZ's is newer — never a blind overwrite, never a
     * blind reject (issue 12 §2's explicit requirement).
     *
     * Field scope is deliberately narrow and explicit, never "whatever keys
     * BZ happens to send": exactly the fields bzParticipantList() itself
     * exposes (this is a return-sync of that same contract, not a
     * general-purpose registration PATCH) minus payment/amount fields, which
     * §5 says BZ never receives in the first place and so can never
     * legitimately claim to have edited, PLUS checked_in_at/checked_in_by
     * (E3.6, issue 12 §4) — the one pair of fields this endpoint accepts that
     * BZ never pulled from bzParticipantList() in the first place, because the
     * Odprawa fact originates at BZ, not on the platform (see this method's
     * "checked_in_at/checked_in_by" paragraph below for the LWW rationale).
     * Any fieldKey outside these groups — including a payment-shaped key an
     * untrusted or buggy caller might send ('pricePaid', 'totalAmount',
     * 'paid', ...) — is rejected outright as out of scope, never silently
     * merged.
     *
     * Timestamp comparison is per-row/per-answer, not per-attribute (a
     * documented, deliberately coarser granularity than "one clock per
     * field" — see migration 050's own docblock for why that is enough for
     * this field set):
     *  - first_name/last_name: compared against `competitors.updated_at`
     *    (native `ON UPDATE CURRENT_TIMESTAMP`, pre-existing).
     *  - contact_email/category/bib_number: compared against
     *    `registrations.updated_at` (migration 050), stamped explicitly by
     *    update() and by this method itself — never DB-magic, matching this
     *    table's existing VARCHAR(64)/`date(DATE_ATOM)` timestamp convention.
     *  - custom fields (bzVisibleColumns() subset only): compared against
     *    that field's own `form_field_answers.updated_at` (native,
     *    pre-existing, already per-answer-row, so already as fine-grained as
     *    it gets).
     * A NULL/missing platform timestamp always loses to an incoming BZ edit —
     * "never touched since we started tracking" is the correct, permissive
     * starting state for LWW (see migration 050's own docblock).
     *
     * checked_in_at/checked_in_by (E3.6, issue 12 §4 — "BZ zwraca status
     * odprawy przy każdej synchronizacji"): compared against
     * `registrations.updated_at`, the exact same coarse row-level clock as
     * category/bib_number/contact_email above — deliberately NOT given a
     * special-cased "monotonic, never goes backwards" rule even though a
     * real Odprawa is close to monotonic in practice (once checked in, it
     * essentially never gets un-checked). Two reasons: (1) the ticket that
     * asked for this explicitly prefers staying inside the one LWW mechanism
     * already established here over inventing a second, inconsistent one,
     * and (2) a correctly-behaving BZ always sends an honest `changedAt` for
     * the moment it recorded the check-in, so ordinary LWW already produces
     * the right outcome without any extra rule — the only case a
     * monotonic-only rule would additionally reject is a BZ-side correction
     * of a mistaken check-in (clearing it back to NULL), which is a
     * legitimate edit this endpoint should honor like any other, not silently
     * refuse. `checked_in_at`'s incoming value must itself parse as a
     * timestamp (or be explicitly `null`, clearing the check-in) — an
     * unparseable value is rejected as `invalid_value`, mirroring the custom
     * form-field branch below rather than being stored as opaque garbage in a
     * column every other reader treats as a timestamp.
     *
     * Every field is returned as either applied or rejected — never silently
     * dropped — so the caller (routes.php) can write ONE activity-log entry
     * per call that shows every field's fate: applied with old -> new, or
     * rejected-as-stale with both the incoming and the platform's own current
     * value. That entry is the actual "ślad" the spec requires. This method
     * itself never touches activity_logs (same division of responsibility as
     * correctFormSnapshot() above).
     *
     * @param array<string, array{value: mixed, changedAt: string}> $fields fieldKey => {value, changedAt}
     * @return array{
     *     applied: array<string, array{old: mixed, new: mixed}>,
     *     rejected: array<string, array{reason: string, incomingValue: mixed, currentValue: mixed}>
     * }
     * @throws \RuntimeException 'registration_not_found' when $registrationId doesn't exist or isn't part of $eventId
     */
    public function applyBzFieldSync(string $eventId, string $registrationId, array $fields): array
    {
        $row = $this->bzSyncRow($registrationId);
        if ($row === null || (string) $row['eventId'] !== $eventId) {
            throw new \RuntimeException('registration_not_found');
        }

        $competitorRepository = new CompetitorRepository($this->pdo);
        $competitor = $row['competitorId'] !== null ? $competitorRepository->find((string) $row['competitorId']) : null;

        $formFieldRepository = new FormFieldRepository($this->pdo);
        $answerRepository = new FormFieldAnswerRepository($this->pdo);
        $customFieldsByKey = [];
        foreach ($formFieldRepository->bzVisibleColumns($eventId) as $field) {
            $customFieldsByKey[$field['fieldKey']] = $field;
        }

        $applied = [];
        $rejected = [];
        $competitorUpdates = [];
        $registrationUpdates = [];

        foreach ($fields as $fieldKey => $entry) {
            $fieldKey = (string) $fieldKey;
            $incomingValue = is_array($entry) ? ($entry['value'] ?? null) : null;
            $changedAtRaw = is_array($entry) ? ($entry['changedAt'] ?? null) : null;
            $changedAt = is_string($changedAtRaw) && $changedAtRaw !== '' ? $this->parseTimestamp($changedAtRaw) : null;

            if ($changedAt === null) {
                $rejected[$fieldKey] = ['reason' => 'invalid_changed_at', 'incomingValue' => $incomingValue, 'currentValue' => null];
                continue;
            }

            if (array_key_exists($fieldKey, self::BZ_SYNCABLE_COMPETITOR_FIELDS)) {
                if ($competitor === null) {
                    $rejected[$fieldKey] = ['reason' => 'no_competitor_profile', 'incomingValue' => $incomingValue, 'currentValue' => null];
                    continue;
                }
                $attribute = self::BZ_SYNCABLE_COMPETITOR_FIELDS[$fieldKey];
                $currentValue = $competitor[$attribute] ?? null;
                $platformChangedAt = $this->parseDatabaseTimestamp((string) ($competitor['updatedAt'] ?? ''));
                if ($platformChangedAt !== null && $platformChangedAt >= $changedAt) {
                    $rejected[$fieldKey] = ['reason' => 'stale', 'incomingValue' => $incomingValue, 'currentValue' => $currentValue];
                    continue;
                }
                $applied[$fieldKey] = ['old' => $currentValue, 'new' => $incomingValue];
                $competitorUpdates[$attribute] = $incomingValue;
                continue;
            }

            if (array_key_exists($fieldKey, self::BZ_SYNCABLE_REGISTRATION_FIELDS)) {
                $attribute = self::BZ_SYNCABLE_REGISTRATION_FIELDS[$fieldKey];
                $currentValue = $row[$attribute] ?? null;
                $platformChangedAt = $row['updatedAt'] !== null ? $this->parseTimestamp((string) $row['updatedAt']) : null;
                if ($platformChangedAt !== null && $platformChangedAt >= $changedAt) {
                    $rejected[$fieldKey] = ['reason' => 'stale', 'incomingValue' => $incomingValue, 'currentValue' => $currentValue];
                    continue;
                }
                // E3.6: checked_in_at is a timestamp column — validate it parses
                // (or is an explicit null, clearing the check-in) rather than
                // trusting whatever BZ sends into a column every other reader
                // treats as DATE_ATOM (see this method's own docblock above).
                if ($fieldKey === 'checked_in_at' && $incomingValue !== null) {
                    $normalizedCheckIn = is_string($incomingValue) && $incomingValue !== ''
                        ? $this->parseTimestamp($incomingValue)
                        : null;
                    if ($normalizedCheckIn === null) {
                        $rejected[$fieldKey] = ['reason' => 'invalid_value', 'incomingValue' => $incomingValue, 'currentValue' => $currentValue];
                        continue;
                    }
                    $incomingValue = $normalizedCheckIn->format(DATE_ATOM);
                }
                // checked_in_by is an opaque BZ-side identifier/name (no
                // platform user, no FK — see migration 051) stored as-is;
                // only coerce a non-null scalar to a string so a JSON number
                // or bool from a loose BZ client can't reach the VARCHAR
                // column as its native PHP type.
                if ($fieldKey === 'checked_in_by' && $incomingValue !== null) {
                    $incomingValue = is_scalar($incomingValue) ? (string) $incomingValue : null;
                    if ($incomingValue === null) {
                        $rejected[$fieldKey] = ['reason' => 'invalid_value', 'incomingValue' => null, 'currentValue' => $currentValue];
                        continue;
                    }
                }
                $applied[$fieldKey] = ['old' => $currentValue, 'new' => $incomingValue];
                $registrationUpdates[$fieldKey === 'contact_email' ? 'user_email' : $fieldKey] = $incomingValue;
                continue;
            }

            if (array_key_exists($fieldKey, $customFieldsByKey)) {
                $field = $customFieldsByKey[$fieldKey];
                $existingAnswer = $answerRepository->findByFieldAndRegistration((string) $field['id'], $registrationId);
                $currentValue = $existingAnswer['valueText'] ?? $existingAnswer['valueJson'] ?? null;
                $platformChangedAt = $existingAnswer !== null ? $this->parseDatabaseTimestamp((string) ($existingAnswer['updatedAt'] ?? '')) : null;
                if ($platformChangedAt !== null && $platformChangedAt >= $changedAt) {
                    $rejected[$fieldKey] = ['reason' => 'stale', 'incomingValue' => $incomingValue, 'currentValue' => $currentValue];
                    continue;
                }
                try {
                    $answerRepository->saveAnswer($formFieldRepository, (string) $field['id'], $registrationId, $incomingValue);
                } catch (\InvalidArgumentException) {
                    $rejected[$fieldKey] = ['reason' => 'invalid_value', 'incomingValue' => $incomingValue, 'currentValue' => $currentValue];
                    continue;
                }
                $applied[$fieldKey] = ['old' => $currentValue, 'new' => $incomingValue];
                continue;
            }

            // Defense in depth (issue 12 §5, "bez kwot"): any key outside the
            // three groups above — including a payment/amount-shaped key BZ
            // was never handed and so can never legitimately have edited — is
            // rejected outright, never merged into anything.
            $rejected[$fieldKey] = ['reason' => 'field_not_in_scope', 'incomingValue' => $incomingValue, 'currentValue' => null];
        }

        if ($competitorUpdates !== []) {
            $competitorRepository->update((string) $row['competitorId'], $competitorUpdates);
        }
        if ($registrationUpdates !== []) {
            // Apply-time "now", not BZ's own changedAt: mirrors how
            // competitors.updated_at/form_field_answers.updated_at already
            // behave above (both are native ON UPDATE columns that always
            // become "now" on any write, never the semantic edit instant) —
            // one consistent rule across all three field groups, and still
            // correct for LWW, since "now" is always >= any legitimately
            // past changedAt.
            $registrationUpdates['updated_at'] = date(DATE_ATOM);
            $this->updateById('registrations', $registrationId, $registrationUpdates);
        }

        return ['applied' => $applied, 'rejected' => $rejected];
    }

    /** @return array<string, mixed>|null */
    private function bzSyncRow(string $registrationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, event_id AS eventId, competitor_id AS competitorId, category, bib_number AS bibNumber,
                    user_email AS userEmail, checked_in_at AS checkedInAt, checked_in_by AS checkedInBy,
                    updated_at AS updatedAt
             FROM registrations WHERE id = :id'
        );
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** Session offset of the database server against UTC, in seconds; asked once. */
    private ?int $databaseUtcOffset = null;

    /**
     * Reads a timestamp that MySQL rendered, as the absolute moment it stands for.
     *
     * `parseTimestamp()` above is right for the columns this codebase writes itself
     * (`registrations.updated_at` and friends are VARCHAR(64) holding DATE_ATOM, so
     * they carry their own offset). It is wrong for a native TIMESTAMP column, which
     * comes back as bare wall-clock in the *server's* timezone — `2026-09-16 01:12:52`
     * with no offset — and which PHP would then read in its own.
     *
     * Those two timezones are not the same here and generally are not anywhere: PHP
     * runs on `date.timezone = UTC` while MySQL's `time_zone` is `SYSTEM`, Europe/Warsaw
     * on both this machine and production. Parsed naively, every native timestamp looks
     * two hours into the future, so the LWW comparison below concluded that the platform
     * had touched the row after any BZ edit made in the last two hours and rejected it
     * as stale. That is a silent, wrong-direction data loss — BZ's edit is dropped and
     * the caller is told the platform's value is newer — and it had gone unnoticed
     * because the contract test covering it could not authenticate (see
     * BzParticipantSyncContractTest::setUp()).
     *
     * TIMESTAMP columns are stored internally as UTC and converted on the way out, so
     * the server's own offset is exactly what was applied and exactly what has to be
     * undone. An incoming value that already carries an offset keeps it — the timezone
     * argument only fills in what a bare string omits.
     */
    private function parseDatabaseTimestamp(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        if ($this->databaseUtcOffset === null) {
            $this->databaseUtcOffset = (int) $this->pdo
                ->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')
                ->fetchColumn();
        }

        $offset = $this->databaseUtcOffset;
        $timezone = sprintf('%+03d:%02d', intdiv($offset, 3600), intdiv(abs($offset) % 3600, 60));

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone($timezone));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * E1.14 out-of-scope seam: the spec's "wyniki, certyfikaty i eksporty
     * czytają już jego [snapshotu]" names three subsystems that would need to
     * switch from live to frozen reads once an Event finishes. Grepped before
     * writing this ticket's code: `src/Domain/Results` and
     * `src/Domain/Certificate` have zero references to form fields/answers
     * today, and there is no export subsystem anywhere in this codebase —
     * none of the three read registration form data at all yet, so there is
     * nothing to wire this snapshot into there. Same
     * documented-seam-not-invented-feature stance as E1.5's
     * `initiateGatewayRefund()`/E1.10's mail seams: whenever any of the three
     * is actually built, it should read `formSnapshotFor()` (finished Events)
     * the same way `publicStartList()` does below, not re-derive its own live
     * resolution.
     *
     * E1.13 verification fix: wires FormFieldRepository::publicListColumns()
     * (form-item order + public_list_visible flag + unconditional
     * is_confidential exclusion) into the real public start-list path — it
     * was previously only exercised by FormFieldRepositoryTest in isolation,
     * never by any endpoint. Adds a `formFields` array to every registration
     * row: exactly the columns publicListColumns() says belong on the public
     * list, in that order, each resolved to whatever value already exists
     * for it today (a custom field's stored answer, or a system field's
     * value on this registration/its linked competitor).
     *
     * Deliberately does NOT invent storage for a system key that has none in
     * this schema today — country/city/postal_code/voivodeship/address/
     * anonymization are seeded into the catalog (FormFieldCatalog) but have
     * no actual column anywhere (grepped before writing this: no migration
     * ever added them to `competitors`) — those simply resolve to null here,
     * same as an unanswered custom field would. Closing that pre-existing gap
     * is out of scope for this fix.
     *
     * E1.14: this is now the fork point between "live" and "frozen" — before
     * an Event is `finished`, values keep coming straight from the live
     * Competitor/Registration record (attachFrozenFormFields() below is never
     * consulted, so a post-registration typo fix on the Competitor keeps
     * showing up here exactly as before this ticket); once `finished`, every
     * Zapis instead serves its snapshot (see attachFrozenFormFields()'s own
     * docblock for why "first read after finish" stands in for "the moment of
     * finish" in this no-cron codebase).
     *
     * @param array<int, array<string, mixed>> $registrations
     * @return array<int, array<string, mixed>>
     */
    private function attachPublicFormFields(string $eventId, array $registrations): array
    {
        if ($registrations === []) {
            return $registrations;
        }

        $columns = (new FormFieldRepository($this->pdo))->publicListColumns($eventId);
        if ($columns === []) {
            return array_map(static fn (array $r): array => [...$r, 'formFields' => []], $registrations);
        }

        if ($this->eventIsFinished($eventId)) {
            return $this->attachFrozenFormFields($registrations, $columns);
        }

        return $this->resolveLiveFormFields($registrations, $columns);
    }

    /**
     * `events.publication_status` for one Wydarzenie, read directly (same
     * "cross-table read without a repository dependency" style as
     * paymentGatewayProviderFor()/paymentConfigType() above) — never `'finished'`
     * is treated as "not yet finished" for a missing/unknown row, same
     * conservative default as everywhere else in this class.
     */
    private function eventIsFinished(string $eventId): bool
    {
        $stmt = $this->pdo->prepare('SELECT publication_status FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        return $stmt->fetchColumn() === 'finished';
    }

    /**
     * Live resolution, shared by two callers: the pre-finish read path
     * (attachPublicFormFields(), unchanged behavior) and the finished-Event
     * lazy-capture path (attachFrozenFormFields(), E1.14) — the snapshot that
     * ticket freezes is always exactly what this method would have returned
     * live at the moment it is first called after finish. Refactored out of
     * attachPublicFormFields() rather than duplicated, per the ticket.
     *
     * @param array<int, array<string, mixed>> $registrations
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, array<string, mixed>>
     */
    private function resolveLiveFormFields(array $registrations, array $columns): array
    {
        $answerRepository = new FormFieldAnswerRepository($this->pdo);
        $competitorRepository = new CompetitorRepository($this->pdo);
        $competitorCache = [];

        return array_map(function (array $registration) use ($columns, $answerRepository, $competitorRepository, &$competitorCache): array {
            $competitor = null;
            $competitorId = $registration['competitorId'] ?? null;
            if ($competitorId !== null) {
                if (!array_key_exists($competitorId, $competitorCache)) {
                    $competitorCache[$competitorId] = $competitorRepository->find((string) $competitorId);
                }
                $competitor = $competitorCache[$competitorId];
            }

            $answersByFieldId = [];
            foreach ($answerRepository->forRegistration((string) $registration['id']) as $answer) {
                $answersByFieldId[$answer['formFieldId']] = $answer['valueText'] ?? $answer['valueJson'] ?? null;
            }

            $registration['formFields'] = array_map(
                fn (array $field): array => [
                    'fieldKey' => $field['fieldKey'],
                    'label' => $field['labelOverride'] ?? (FormFieldCatalog::find($field['fieldKey'])['label'] ?? $field['fieldKey']),
                    'value' => $this->resolvePublicFieldValue($field, $registration, $competitor, $answersByFieldId),
                ],
                $columns
            );

            return $registration;
        }, $registrations);
    }

    /**
     * Freezes and serves the finished-Event snapshot (spec 10 pkt 2, "przy
     * przejściu Wydarzenia w stan zakończony Zapis utrwala snapshot"). This
     * codebase has no cron (see EventRepository::lazilyFinishPastEvents()'s own
     * docblock) — the Event's row already flips to `finished` lazily, on the
     * first read after its date passes, before this ever runs (every caller of
     * publicStartList() reads the Event first — see routes.php's start-list
     * handler, which calls `$events->find()` before `$registrations->
     * publicStartList()`). This mirrors that same lazy-on-read shape one level
     * down: the FIRST read of a given Registration's form fields after its
     * Event is `finished` computes the payload once (resolveLiveFormFields(),
     * shared with the pre-finish path) and freezes it
     * (captureFormSnapshotIfMissing() — guarded so it can never overwrite an
     * already-captured snapshot, "stamped once" per the ticket); every later
     * read — including one where the underlying Competitor was edited
     * afterward — serves that same frozen JSON, never recomputed. The only
     * thing that can ever change a captured snapshot afterward is the explicit,
     * audited correction endpoint (correctFormSnapshot()).
     *
     * @param array<int, array<string, mixed>> $registrations
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, array<string, mixed>>
     */
    private function attachFrozenFormFields(array $registrations, array $columns): array
    {
        $ids = array_column($registrations, 'id');
        $snapshots = $this->fetchFormSnapshots($ids);

        $uncaptured = array_values(array_filter(
            $registrations,
            static fn (array $r): bool => !array_key_exists($r['id'], $snapshots)
        ));
        if ($uncaptured !== []) {
            foreach ($this->resolveLiveFormFields($uncaptured, $columns) as $resolved) {
                $this->captureFormSnapshotIfMissing((string) $resolved['id'], $resolved['formFields']);
                $snapshots[$resolved['id']] = $resolved['formFields'];
            }
        }

        return array_map(
            static fn (array $r): array => [...$r, 'formFields' => $snapshots[$r['id']] ?? []],
            $registrations
        );
    }

    /**
     * @param array<int, string> $ids
     * @return array<string, array<int, array<string, mixed>>> keyed by registration id — only ids that already have a captured snapshot are present
     */
    private function fetchFormSnapshots(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, form_data_snapshot AS formDataSnapshot FROM registrations
             WHERE id IN ({$placeholders}) AND form_data_snapshot IS NOT NULL"
        );
        $stmt->execute(array_values($ids));

        $snapshots = [];
        foreach ($stmt->fetchAll() as $row) {
            $snapshots[$row['id']] = json_decode((string) $row['formDataSnapshot'], true) ?: [];
        }

        return $snapshots;
    }

    /**
     * Guarded by `form_snapshot_captured_at IS NULL` — the "stamped once, not
     * recomputed again afterward" half of the ticket's requirement. A
     * retroactive correction (correctFormSnapshot()) writes
     * `form_data_snapshot` directly and deliberately does NOT re-null this
     * column, so a later lazy read here can never clobber a correction with a
     * freshly-recomputed live value.
     *
     * @param array<int, array<string, mixed>> $formFields
     */
    private function captureFormSnapshotIfMissing(string $registrationId, array $formFields): void
    {
        $this->pdo->prepare(
            'UPDATE registrations
             SET form_data_snapshot = :snapshot, form_snapshot_captured_at = :capturedAt
             WHERE id = :id AND form_snapshot_captured_at IS NULL'
        )->execute([
            'snapshot' => json_encode($formFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'capturedAt' => date(DATE_ATOM),
            'id' => $registrationId,
        ]);
    }

    /**
     * E2.6 ("Eksport listy startowej: CSV (Windows-1250) + XLSX", spec 21
     * Answer §3): organizer-facing export rows for one Distance. Reuses the
     * exact same live/frozen fork as attachPublicFormFields() —
     * eventIsFinished() picks resolveLiveFormFields() (pre-finish) or
     * attachFrozenFormFields() (post-finish, same frozen snapshot the public
     * start list and the correction endpoint already serve) — but against
     * FormFieldRepository::exportColumns() instead of publicListColumns():
     * every non-hidden, non-confidential field (exportColumns() excludes
     * `is_confidential` fields the same way publicListColumns() does — see
     * that method's docblock), because unlike the public start list this
     * export doesn't also require `publicListVisible`, an organizer/staff
     * export being a broader audience than the public page in that one way
     * only.
     *
     * Columns = every qualifying form field (in form order) + numer
     * startowy + status płatności — deliberately never a monetary amount
     * (spec: "bez kwot", Rozliczenie stays internal per ticket 13); the
     * payment-status column is the ONLY money-adjacent thing here, and it is
     * a status label, not a figure.
     *
     * Only the Distance's own Zapisy are included, `removed` excluded (an
     * organizer-deleted Zapis is no longer part of the roster to hand to a
     * printer or an external tool) — pending/confirmed/cancelled/expired are
     * all kept, because the spec's own payment-status vocabulary
     * (opłacone/oczekujące/wygasłe/anulowane/zwrócone) only makes sense if
     * rows in every one of those states can appear.
     *
     * @return array{columns: array<int, array{key: string, label: string}>, rows: array<int, array<string, mixed>>}
     */
    public function startListExportRows(string $eventId, string $distanceId): array
    {
        $this->lazilyExpireUnpaidRegistrations();

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM registrations WHERE event_id = :eventId AND distance_id = :distanceId AND status != 'removed' ORDER BY registered_at ASC"
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
        $registrations = array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());

        $formColumns = (new FormFieldRepository($this->pdo))->exportColumns($eventId);

        if ($registrations !== [] && $formColumns !== []) {
            $registrations = $this->eventIsFinished($eventId)
                ? $this->attachFrozenFormFields($registrations, $formColumns)
                : $this->resolveLiveFormFields($registrations, $formColumns);
        } else {
            $registrations = array_map(static fn (array $r): array => [...$r, 'formFields' => []], $registrations);
        }

        $columns = [];
        foreach ($formColumns as $field) {
            $columns[] = [
                'key' => $field['fieldKey'],
                'label' => $field['labelOverride'] ?? (FormFieldCatalog::find($field['fieldKey'])['label'] ?? $field['fieldKey']),
            ];
        }
        $columns[] = ['key' => 'bibNumber', 'label' => 'Numer startowy'];
        $columns[] = ['key' => 'paymentStatus', 'label' => 'Status płatności'];

        $rows = [];
        foreach ($registrations as $registration) {
            $row = [];
            foreach ((array) $registration['formFields'] as $formField) {
                $row[$formField['fieldKey']] = $formField['value'];
            }
            $row['bibNumber'] = $registration['bibNumber'] ?? '';
            $row['paymentStatus'] = $this->exportPaymentStatusLabel($registration);
            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Status-only label (never an amount) for the export's "Status
     * płatności" column, covering exactly the five values the spec names
     * (opłacone/oczekujące/wygasłe/anulowane/zwrócone). `registrations.status`
     * already carries four of them directly; the fifth ("zwrócone") is a
     * refund cycle layered on top (registration_refunds, E1.5) — a
     * `confirmed` Zapis with a completed refund cycle is already flipped to
     * `cancelled` by markRefunded() on a full refund, so checking the refund
     * row first and falling back to `status` covers both full and (were a
     * partial refund ever surfaced here) partial cases without
     * double-reporting a Zapis as both "anulowana" and "zwrócone".
     *
     * @param array<string, mixed> $registration
     */
    private function exportPaymentStatusLabel(array $registration): string
    {
        $refund = $this->refund((string) $registration['id']);
        if ($refund !== null && $refund['status'] === 'refunded') {
            return 'Zwrócona';
        }

        return match ((string) ($registration['status'] ?? '')) {
            'confirmed' => 'Opłacona',
            'pending' => 'Oczekująca',
            'expired' => 'Wygasła',
            'cancelled' => 'Anulowana',
            default => (string) ($registration['status'] ?? ''),
        };
    }

    /**
     * E8.2 (spec 21 Answer §2): built-in organizer cross-sections — NOT from the
     * configurable form (that's E2.5's FormFieldRepository::aggregatesForEvent(),
     * left untouched), only from the Zapis record itself. Every number here is a
     * live query, per spec §5 ("Zestawienia organizatora... liczą się na żywo") —
     * no new materialized counters, the one exception (event_distances.registered_count,
     * E1.3) is a different, already-built concern this method doesn't touch.
     *
     * Gender/club are genuinely OMITTED (key absent, not an empty/zeroed array)
     * when the corresponding system Pozycja formularza (FormFieldCatalog) is
     * `hidden` for this Event — mirrors aggregatesForEvent()'s own "no field ->
     * no entry" behavior exactly, just checked against form_fields.state instead
     * of "does a custom field of this type exist at all". Age category has no
     * system form-field key at all (FormFieldCatalog's closed 13-position
     * catalog has no "age_category" entry) — the only existing per-Distance
     * age-classification config in the schema is `event_distances.age_categories`
     * (written by ticket 10's distance editor, read nowhere until this ticket),
     * so a Distance's age-category breakdown is included only when that JSON
     * array is non-empty for that Distance, and the whole `ageCategories` key is
     * omitted event-wide when no Distance configures any.
     *
     * Uses ensureSystemCatalog() (not listForEvent()) so an Event whose 13 system
     * positions were never lazily seeded still reports its default states - same
     * defence as bzParticipantList().
     *
     * @return array<string, mixed>
     */
    public function organizerStats(string $eventId): array
    {
        $this->lazilyExpireUnpaidRegistrations();

        $stats = [
            'byDistance' => $this->statsByDistance($eventId),
            'byPaymentStatus' => $this->statsByPaymentStatus($eventId),
            'signupCurve' => $this->statsSignupCurve($eventId),
        ];

        $formFields = new FormFieldRepository($this->pdo);
        $systemFields = array_filter(
            $formFields->ensureSystemCatalog($eventId),
            static fn (array $f): bool => $f['kind'] === 'system'
        );
        $stateByKey = [];
        foreach ($systemFields as $field) {
            $stateByKey[$field['fieldKey']] = $field['state'];
        }

        if (($stateByKey['gender'] ?? FormFieldCatalog::STATE_HIDDEN) !== FormFieldCatalog::STATE_HIDDEN) {
            $stats['byGender'] = $this->statsByCompetitorColumn($eventId, 'gender', [
                'M' => 'Mężczyzna',
                'K' => 'Kobieta',
            ]);
        }

        if (($stateByKey['club'] ?? FormFieldCatalog::STATE_HIDDEN) !== FormFieldCatalog::STATE_HIDDEN) {
            $stats['byClub'] = $this->statsByCompetitorColumn($eventId, 'club', []);
        }

        $ageCategories = $this->statsByAgeCategory($eventId);
        if ($ageCategories !== []) {
            $stats['ageCategories'] = $ageCategories;
        }

        return $stats;
    }

    /** @return array<int, array{distanceId: string, distanceName: string, count: int}> */
    private function statsByDistance(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.distance_id AS distanceId, MAX(r.distance_name) AS distanceName, COUNT(*) AS cnt
             FROM registrations r
             WHERE r.event_id = :eventId AND r.status NOT IN ('cancelled', 'removed', 'expired')
             GROUP BY r.distance_id"
        );
        $stmt->execute(['eventId' => $eventId]);
        return array_map(static fn (array $row): array => [
            'distanceId' => (string) $row['distanceId'],
            'distanceName' => (string) $row['distanceName'],
            'count' => (int) $row['cnt'],
        ], $stmt->fetchAll());
    }

    /**
     * Same five-value vocabulary as exportPaymentStatusLabel() (E2.6) —
     * refunded takes priority over the underlying `cancelled` a full refund
     * already flips the Zapis to (markRefunded()), so a Zapis is never
     * double-counted under both buckets. Deliberately keeps `removed` out
     * (an organizer-deleted Zapis, same filter startListExportRows() already
     * applies) but — unlike the other breakdowns in this class — keeps
     * cancelled/expired IN, since the whole point of this breakdown is to
     * show exactly those states distinctly.
     *
     * @return array<int, array{status: string, label: string, count: int}>
     */
    private function statsByPaymentStatus(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                CASE
                    WHEN rf.status = 'refunded' THEN 'refunded'
                    WHEN r.status = 'confirmed' THEN 'paid'
                    WHEN r.status = 'pending' THEN 'pending'
                    WHEN r.status = 'expired' THEN 'expired'
                    WHEN r.status = 'cancelled' THEN 'cancelled'
                    ELSE r.status
                END AS bucket,
                COUNT(*) AS cnt
             FROM registrations r
             LEFT JOIN registration_refunds rf ON rf.registration_id = r.id
             WHERE r.event_id = :eventId AND r.status != 'removed'
             GROUP BY bucket"
        );
        $stmt->execute(['eventId' => $eventId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['bucket']] = (int) $row['cnt'];
        }

        $labels = [
            'paid' => 'Opłacone',
            'pending' => 'Oczekujące',
            'expired' => 'Wygasłe',
            'cancelled' => 'Anulowane',
            'refunded' => 'Zwrócone',
        ];

        $result = [];
        foreach ($labels as $key => $label) {
            $result[] = ['status' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0];
        }
        return $result;
    }

    /**
     * "Krzywa zapisów w czasie" (spec 21 Answer §2) — daily registration counts
     * since the Event's registration opened, the cross-section the spec calls
     * out as the one organizers look at most. Bucketed on the date portion of
     * `registered_at` (DATE_ATOM string, so the first 10 characters are always
     * the calendar date regardless of timezone offset suffix) — a plain SQL
     * substring + GROUP BY, no new column. Days with zero Zapisy are filled in
     * with count 0 so a client-side line/area chart never has to guess whether
     * a gap in the data means "zero that day" or "no data fetched for that
     * day" — same "seed every bucket, even at zero" stance E2.5's
     * countAnswersForField() already takes for configured option values.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function statsSignupCurve(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT registration_open_date AS registrationOpenDate FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $openDate = $stmt->fetchColumn();
        $openDate = is_string($openDate) && $openDate !== '' ? substr($openDate, 0, 10) : null;

        $stmt = $this->pdo->prepare(
            "SELECT SUBSTRING(r.registered_at, 1, 10) AS day, COUNT(*) AS cnt
             FROM registrations r
             WHERE r.event_id = :eventId AND r.status NOT IN ('cancelled', 'removed', 'expired')
             GROUP BY day
             ORDER BY day ASC"
        );
        $stmt->execute(['eventId' => $eventId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['day']] = (int) $row['cnt'];
        }

        if ($counts === []) {
            return [];
        }

        $days = array_keys($counts);
        sort($days);
        $start = $openDate !== null && $openDate <= $days[0] ? $openDate : $days[0];
        $end = date('Y-m-d');
        if ($end < $days[count($days) - 1]) {
            $end = $days[count($days) - 1];
        }

        $result = [];
        try {
            $cursor = new \DateTimeImmutable($start);
            $last = new \DateTimeImmutable($end);
        } catch (\Exception) {
            return array_map(static fn (string $day): array => ['date' => $day, 'count' => $counts[$day]], $days);
        }
        while ($cursor <= $last) {
            $day = $cursor->format('Y-m-d');
            $result[] = ['date' => $day, 'count' => $counts[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }

        return $result;
    }

    /**
     * Shared implementation for gender/club — both live on `competitors`
     * (CompetitorRepository, not form_field_answers — see FormFieldCatalog's
     * storageLocation for either key), joined through
     * `registrations.competitor_id`. `$labels` seeds known values at zero
     * (mirrors countAnswersForField()'s option-seeding for gender's closed
     * M/K vocabulary); club is free text with no closed set, so it is left to
     * grow from whatever distinct values are actually on file, sorted by
     * count desc.
     *
     * @param array<string, string> $labels fixed key => label seed; empty for open vocabularies
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function statsByCompetitorColumn(string $eventId, string $column, array $labels): array
    {
        if (!in_array($column, ['gender', 'club'], true)) {
            throw new \InvalidArgumentException('Unsupported competitor column for stats.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT c.{$column} AS value, COUNT(*) AS cnt
             FROM registrations r
             INNER JOIN competitors c ON c.id = r.competitor_id
             WHERE r.event_id = :eventId AND r.status NOT IN ('cancelled', 'removed', 'expired')
               AND c.{$column} IS NOT NULL AND c.{$column} <> ''
             GROUP BY c.{$column}"
        );
        $stmt->execute(['eventId' => $eventId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['value']] = (int) $row['cnt'];
        }

        $result = [];
        foreach ($labels as $key => $label) {
            $result[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0];
            unset($counts[$key]);
        }
        foreach ($counts as $key => $count) {
            $result[] = ['key' => $key, 'label' => $key, 'count' => $count];
        }

        usort($result, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Age-category breakdown, per Distance — classifies each live Zapis's
     * Competitor (gender + birth year, both on `competitors`) against that
     * Distance's own `event_distances.age_categories` (ticket 10's distance
     * editor writes {id, name, gender, birthYearFrom, birthYearTo}; this is
     * the first code anywhere that reads it — see FormFieldCatalog's docblock,
     * grepped before writing this). Distances with an empty/absent
     * `age_categories` are left out of the returned array entirely, not
     * included with an empty `counts` list — same "field not configured -> no
     * entry" contract as the rest of this method's caller.
     *
     * @return array<int, array{distanceId: string, distanceName: string, counts: array<int, array{key: string, label: string, count: int}>}>
     */
    private function statsByAgeCategory(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, age_categories AS ageCategories FROM event_distances WHERE event_id = :eventId ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['eventId' => $eventId]);
        $distances = $stmt->fetchAll();

        $result = [];
        foreach ($distances as $distance) {
            $raw = $distance['ageCategories'] ?? null;
            $categories = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($categories) || $categories === []) {
                continue;
            }

            $distanceId = (string) $distance['id'];
            $stmt2 = $this->pdo->prepare(
                "SELECT c.gender AS gender, c.birth_date AS birthDate
                 FROM registrations r
                 INNER JOIN competitors c ON c.id = r.competitor_id
                 WHERE r.event_id = :eventId AND r.distance_id = :distanceId
                   AND r.status NOT IN ('cancelled', 'removed', 'expired')"
            );
            $stmt2->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
            $competitors = $stmt2->fetchAll();

            $counts = [];
            foreach ($categories as $category) {
                $key = (string) ($category['id'] ?? $category['name'] ?? '');
                if ($key === '') {
                    continue;
                }
                $label = (string) ($category['name'] ?? $key);
                $gender = (string) ($category['gender'] ?? '');
                $from = isset($category['birthYearFrom']) ? (int) $category['birthYearFrom'] : null;
                $to = isset($category['birthYearTo']) ? (int) $category['birthYearTo'] : null;

                $count = 0;
                foreach ($competitors as $competitor) {
                    if ($gender !== '' && (string) ($competitor['gender'] ?? '') !== $gender) {
                        continue;
                    }
                    $birthDate = (string) ($competitor['birthDate'] ?? '');
                    if ($birthDate === '' || strlen($birthDate) < 4) {
                        continue;
                    }
                    $birthYear = (int) substr($birthDate, 0, 4);
                    if ($from !== null && $birthYear < $from) {
                        continue;
                    }
                    if ($to !== null && $birthYear > $to) {
                        continue;
                    }
                    $count++;
                }

                $counts[] = ['key' => $key, 'label' => $label, 'count' => $count];
            }

            if ($counts !== []) {
                $result[] = [
                    'distanceId' => $distanceId,
                    'distanceName' => (string) ($distance['name'] ?? ''),
                    'counts' => $counts,
                ];
            }
        }

        return $result;
    }

    /**
     * Retroactive correction ("korekta wsteczna ze śladem", spec 10 pkt 2) —
     * the ONLY way to change a finished Event's frozen form-field snapshot.
     * Correcting the live Competitor/Registration record directly is how a
     * pre-finish typo is already fixed today (attachPublicFormFields() reads
     * it live in that case) — this method deliberately does NOT build a
     * parallel path for that case, and rejects outright when the Event isn't
     * `finished` yet (registration_form_snapshot_not_finished below).
     *
     * Merges `$updates` (fieldKey => new value) into the existing frozen
     * payload under a row lock, preserving every entry the call doesn't touch,
     * and adding a fresh entry (label defaults to the key itself) for a
     * fieldKey that wasn't part of the snapshot captured at finish — this is a
     * generic "fix this Zapis's frozen form data" tool, not limited to
     * whatever happened to be public-list-visible at the moment of finish.
     *
     * Returns the per-field old/new values (restricted to the fieldKeys in
     * $updates) so the caller (routes.php, per this codebase's established
     * convention — see $logActivity's own doc comment) can write an
     * activity-log entry precise enough to reconstruct who changed what, from
     * what value, to what value, and when — the actual "ślad w rejestrze" the
     * spec requires. This method itself never touches activity_logs.
     *
     * @param array<string, mixed> $updates fieldKey => new value
     * @return array{changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function correctFormSnapshot(string $registrationId, array $updates): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT r.form_data_snapshot AS formDataSnapshot, e.publication_status AS publicationStatus
                 FROM registrations r INNER JOIN events e ON e.id = r.event_id
                 WHERE r.id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $registrationId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new \RuntimeException('registration_not_found');
            }
            if ((string) $row['publicationStatus'] !== 'finished') {
                throw new \RuntimeException('registration_form_snapshot_not_finished');
            }

            $current = $row['formDataSnapshot'] !== null ? (json_decode((string) $row['formDataSnapshot'], true) ?: []) : [];
            $byKey = [];
            foreach ($current as $entry) {
                $byKey[(string) $entry['fieldKey']] = $entry;
            }

            $changes = [];
            foreach ($updates as $fieldKey => $value) {
                $fieldKey = (string) $fieldKey;
                $changes[$fieldKey] = ['old' => $byKey[$fieldKey]['value'] ?? null, 'new' => $value];
                $byKey[$fieldKey] = [
                    'fieldKey' => $fieldKey,
                    'label' => $byKey[$fieldKey]['label'] ?? $fieldKey,
                    'value' => $value,
                ];
            }

            $this->pdo->prepare('UPDATE registrations SET form_data_snapshot = :snapshot WHERE id = :id')
                ->execute([
                    'snapshot' => json_encode(array_values($byKey), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => $registrationId,
                ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['changes' => $changes];
    }

    /** @return array<int, array<string, mixed>> current frozen snapshot entries for one Zapis, [] if never captured */
    public function formSnapshotFor(string $registrationId): array
    {
        $stmt = $this->pdo->prepare('SELECT form_data_snapshot AS formDataSnapshot FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);
        $raw = $stmt->fetchColumn();
        return is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $registration
     * @param array<string, mixed>|null $competitor
     * @param array<string, mixed> $answersByFieldId
     */
    private function resolvePublicFieldValue(array $field, array $registration, ?array $competitor, array $answersByFieldId): mixed
    {
        if ($field['kind'] === 'custom') {
            return $answersByFieldId[$field['id']] ?? null;
        }

        return match ($field['fieldKey']) {
            'contact_email' => $registration['userEmail'] ?? null,
            'first_name' => $competitor['firstName'] ?? null,
            'last_name' => $competitor['lastName'] ?? null,
            'birth_date' => $competitor['birthDate'] ?? null,
            'gender' => $competitor['gender'] ?? null,
            'phone' => $competitor['phone'] ?? null,
            'club' => $competitor['club'] ?? null,
            // country/city/postal_code/voivodeship/address/anonymization: no
            // storage column exists for these anywhere yet — see this
            // method's caller's docblock.
            default => null,
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function byUser(string $userId): array
    {
        $this->lazilyExpireUnpaidRegistrations();
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM registrations WHERE user_id = :userId ORDER BY registered_at DESC');
        $stmt->execute(['userId' => $userId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function find(string $id): ?array
    {
        $this->lazilyExpireUnpaidRegistrations();
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * E8.1 (competitor merge): every Zapis carrying this Zawodnik as its
     * identity — the side-by-side merge comparison's "co niesie ten profil"
     * list, and the repointing/conflict-detection pass both start here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByCompetitor(string $competitorId): array
    {
        $this->lazilyExpireUnpaidRegistrations();
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM registrations WHERE competitor_id = :competitorId ORDER BY registered_at ASC');
        $stmt->execute(['competitorId' => $competitorId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * E8.1: the repointing half of a competitor merge — moves one Zapis from
     * the absorbed profile onto the surviving one. Deliberately a raw column
     * write, not routed through update() (which only accepts the identity
     * *display* fields a registrant fills in, not this administrative
     * ownership change).
     */
    public function repointCompetitor(string $registrationId, string $survivorCompetitorId): void
    {
        $this->updateById('registrations', $registrationId, ['competitor_id' => $survivorCompetitorId]);
    }

    /**
     * Bilet is not a stored entity (E0.2) — it is this Zapis, read as a ticket.
     * There is a 1:1 relationship, so the Zapis id doubles as the ticket id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ticketsByUser(string $userId): array
    {
        return array_map(
            fn (array $registration): array => $this->asTicket($registration),
            $this->byUser($userId)
        );
    }

    /** @param array<string, mixed> $registration */
    private function asTicket(array $registration): array
    {
        // E1.4: an expired Zapis never got paid and never will (it must be re-created
        // from scratch to try again) — the same terminal, "nothing to show up for"
        // shape as cancelled/removed from the Bilet's point of view.
        $status = in_array($registration['status'], ['cancelled', 'removed', 'expired'], true)
            ? 'cancelled'
            : ($registration['checkedInAt'] !== null ? 'used' : 'active');

        return [
            'id' => $registration['id'],
            'registrationId' => $registration['id'],
            'userId' => $registration['userId'],
            'eventId' => $registration['eventId'],
            'eventName' => $registration['eventName'],
            'distanceName' => $registration['distanceName'],
            'bibNumber' => $registration['bibNumber'],
            'status' => $status,
            'checkedInAt' => $registration['checkedInAt'],
            // E3.6 (issue 12 §4): "kto potwierdził" — BZ's own opaque
            // identifier/name for whoever confirmed check-in, surfaced next
            // to checkedInAt on the exact same read path so the competitor's
            // ticket/history view (E1.4's asTicket()) never needs a second
            // fetch to show "odprawiono Cię o 7:42".
            'checkedInBy' => $registration['checkedInBy'] ?? null,
            'issuedAt' => $registration['registeredAt'],
        ];
    }

    /**
     * `paymentDetails` in `$data` is honoured only for trusted internal callers
     * (result-import office entry, dev seed, fixtures). The HTTP route never forwards
     * it (PP.52) and passes `$confirmWhenNothingDue = true`: a Zapis whose frozen
     * Rozliczenie owes 0 is born `confirmed` because there is nothing left to pay
     * (spec 13 "Maszyna stanów": the Zapis status follows the Rozliczenie; spec 07
     * pkt 10 "Zapis o cenie zerowej").
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, bool $confirmWhenNothingDue = false): array
    {
        // E1.4: free up any slots an expired Zapis is still silently holding before
        // this new one checks capacity/tier thresholds below — this is exactly the
        // "wygaśnięcie natychmiast odblokowuje miejsce w tańszej puli" guarantee the
        // spec asks for, not just a display-time cleanup.
        $this->lazilyExpireUnpaidRegistrations();

        $id = $this->id();
        $this->pdo->beginTransaction();
        try {
            // E1.3: locks the Dystans row (SELECT ... FOR UPDATE) for the rest of
            // this transaction — a concurrent create() for the same distance blocks
            // here until this one commits or rolls back, so the capacity check and
            // the price-tier's registeredCount below can never race with another
            // Zapis. See enforceCapacity()/incrementRegisteredCount() for the write
            // side of the same guarantee (belt-and-braces: the affected-rows check
            // there still catches an overrun even if isolation level ever changes).
            $distanceRow = $this->lockDistanceForRegistration((string) $data['eventId'], (string) $data['distanceId']);
            $this->enforceCapacity($distanceRow);
            $team = $this->resolveTeamForRegistration($data, $distanceRow);

            // TD.8 (spec 07 pkt 10 / spec 23 §3): "za drużynę" puts the whole
            // opłata startowa on the captain's Zapis and zeroes it for every
            // other member — but only for a Zapis actually JOINING an
            // existing team (teamId set, createTeam not truthy). The captain
            // (createTeam truthy) is always priced normally, in both modes.
            $isJoiningExistingTeam = $team['teamId'] !== null && empty($data['createTeam']);
            $suppressStartFee = $isJoiningExistingTeam && ($distanceRow['teamPricingMode'] ?? 'per_person') === 'per_team';
            $pricing = $this->pricingForRegistration($data, $distanceRow, $suppressStartFee);
            $paymentDetails = $data['paymentDetails'] ?? null;
            $startFeePaid = is_array($paymentDetails) && (bool) ($paymentDetails['startFeePaid'] ?? false);
            $nothingDue = $confirmWhenNothingDue && $this->amountDueFromItems($pricing['settlementItems']) <= 0.0;
            $bornConfirmed = $startFeePaid || $nothingDue;
            $this->insert('registrations', [
                'id' => $id,
                'event_id' => $data['eventId'],
                'event_name' => $pricing['eventName'],
                'user_id' => $data['userId'],
                'competitor_id' => $data['competitorId'] ?? null,
                'user_name' => $data['userName'],
                'user_email' => $data['userEmail'],
                'distance_id' => $data['distanceId'],
                'distance_name' => $pricing['distanceName'],
                'category' => $data['category'],
                'registered_at' => date(DATE_ATOM),
                'status' => $bornConfirmed ? 'confirmed' : 'pending',
                // E1.11: kod startowy — generowany zawsze, tu, przez platformę (nigdy przez
                // BZ, patrz generateStartCode()'s docblock), więc nigdy nie ma Zapisu bez
                // kodu i nie ma osobnej operacji "wygeneruj kody" po stronie organizatora.
                'qr_code' => $this->generateStartCode(),
                'team_name' => $team['teamName'],
                'team_id' => $team['teamId'],
                'registered_by_id' => $data['registeredById'] ?? null,
                'discount_code' => $pricing['discountCode'],
                // E1.4: frozen at creation, same moment the price itself freezes
                // (07 pkt 5) — a startFeePaid Zapis is born 'confirmed' and never
                // needs to expire, so it gets no deadline at all.
                'payment_expires_at' => $bornConfirmed ? null : $this->paymentExpiryDeadline($distanceRow),
            ]);
            // E1.3: this Zapis is created as 'pending' or 'confirmed' — both count
            // against the Dystans capacity (rozstrzygnięcie 4: "nieanulowane" =
            // pending + confirmed) — so the counter always advances here. Guarded by
            // the same WHERE the lock above already makes redundant in practice, but
            // kept as the atomic affected-rows check (belt-and-braces, see above).
            $this->incrementRegisteredCount((string) $distanceRow['distanceId']);
            $this->createSettlementItems($id, $pricing['settlementItems'], date(DATE_ATOM));
            if ($pricing['discountId'] !== null) {
                $this->markDiscountUsed($pricing['discountId'], $id, $data, $pricing);
            }
            $this->insertMerchandiseOrders($id, $pricing['merchandiseOrders']);
            // Klient nigdy nie przysyła kwot (rozstrzygnięcie 14): niezależnie od tego,
            // co ewentualnie przyszło w paymentDetails.startFeeAmount/insuranceAmount,
            // te dwie kwoty nadpisujemy tym, co właśnie policzyliśmy z Rozliczenia.
            // Flagi startFeePaid/insurancePaid (i ich znaczniki czasu) to potwierdzenie
            // płatności, nie jej kwota — te zostają, czyje by nie były (ticket 20).
            if (is_array($paymentDetails)) {
                $computed = $this->computedPaymentAmounts($pricing['settlementItems']);
                $this->upsertPaymentDetails($id, [
                    ...$paymentDetails,
                    'startFeeAmount' => $computed['startFeeAmount'],
                    'insuranceAmount' => $computed['insuranceAmount'],
                ]);
            } elseif ($nothingDue) {
                $computed = $this->computedPaymentAmounts($pricing['settlementItems']);
                $this->upsertPaymentDetails($id, [
                    'startFeePaid' => true,
                    'startFeePaidAt' => date(DATE_ATOM),
                    'startFeeAmount' => $computed['startFeeAmount'],
                    'insurancePaid' => false,
                    'insuranceAmount' => $computed['insuranceAmount'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $registration = $this->find($id);
        // E1.6/E1.7: trasa A (bramka) starts its gateway session only once the Zapis (and
        // its frozen Rozliczenie) are safely committed — never inside the
        // transaction above, which would hold the Dystans row lock for as long as
        // the outbound HTTP call takes. A startFeePaid Zapis is born 'confirmed'
        // (see above) and never needs a session; only a fresh 'pending' one does.
        if ($registration !== null && $registration['status'] === 'pending') {
            $registration['paymentSession'] = $this->startGatewaySessionIfNeeded($registration);
        }

        // E1.10 mail #4 ("Potwierdzenie zapisu", 17 §1) — fires for every newly
        // created Zapis, paid or not yet. A Zapis born already `confirmed`
        // (startFeePaid at creation, e.g. free entry or an admin-entered paid
        // record) additionally gets mail #5 right away — that really is both
        // events happening at once, not a race the outbox needs to dedupe.
        if ($registration !== null) {
            $this->mailer?->sendRegistrationCreated(
                $id,
                (string) $registration['eventId'],
                (string) $registration['userEmail'],
                (string) $registration['eventName'],
                (string) $registration['distanceName'],
                number_format((float) $registration['totalAmount'], 2, '.', '')
            );
            if ($registration['status'] === 'confirmed') {
                $this->sendPaymentConfirmedMail($id);
            }
        }

        return $registration;
    }

    /**
     * E1.3: statuses that count against a Dystans's capacity limit and price-tier
     * threshold — "nieanulowane" (rozstrzygnięcie 4 w 07-model-cenowy-zapisu.md).
     * Defined as everything *except* the two terminal not-counted statuses (rather
     * than an explicit ['pending', 'confirmed'] allowlist) so it agrees with every
     * other "not cancelled" predicate already in this codebase (publicStartList(),
     * EventRepository's aggregate query) without a second list to keep in sync.
     */
    private const NOT_COUNTED_STATUSES = ['cancelled', 'removed', 'expired'];

    /**
     * E1.4 default gateway window (trasa A / `admin_gateway`), used whenever the
     * event's `paymentConfig.gatewayWindowMinutes` is missing or non-positive —
     * mirrors EventRepository::DEFAULT_GATEWAY_WINDOW_MINUTES's fallback.
     */
    private const DEFAULT_GATEWAY_WINDOW_MINUTES = 45;

    /**
     * TD.7 (fixes E1.4's pragmatic placeholder, cleared once E1.6-E1.8 landed a
     * real per-Zapis payment method): default transfer window (trasa B /
     * `organizer_bank`), used whenever `paymentConfig.transferWindowDays` is
     * missing or non-positive — mirrors EventRepository::DEFAULT_TRANSFER_WINDOW_DAYS.
     */
    private const DEFAULT_TRANSFER_WINDOW_DAYS = 3;

    /**
     * E1.11 (spec 11, "Kod startowy"): the platform-generated QR token that belongs to
     * this Zapis, not to any account/Zawodnik — `zqr_` (kod tożsamości, Zawodnik-owned)
     * is a different token entirely and is E1.12's job, not this one's. Bit-for-bit the
     * same format Biuro Zawodów already generates itself today
     * (`biuro_zawodow-api/src/QrCodeService.php`: `pqr_` + 48 hex chars from
     * `random_bytes(24)`, regex `^pqr_[a-f0-9]{48}$`) — kept identical on purpose so BZ's
     * existing validation and offline scanner client need zero changes; the only thing
     * that changes is *who* generates it (the platform, here, always — never BZ, and
     * never a separate "generate codes" step an organizer could forget to run).
     *
     * Carries no status of its own (no active/used/cancelled column) — its validity is
     * entirely derived from `registrations.status` elsewhere (an expired/cancelled/removed
     * Zapis simply has a dead code, nothing to unset here). Distance changes never touch
     * this — same Zapis, same code; only a reassignment of the Zapis to a different person
     * would rotate it (no such mechanism exists in this codebase today — see the ticket's
     * own note — so there is nothing to hook rotation into yet).
     */
    private function generateStartCode(): string
    {
        return QrCode::startCode();
    }

    /**
     * Locks the Dystans row (and its parent Event, via the JOIN) for the rest of
     * the caller's transaction. Must only be called inside an already-open PDO
     * transaction (create()/updateStatus()) — this is what turns the capacity
     * check and the price-tier's registeredCount read into an atomic unit with
     * the write that follows, closing the classic read-then-write overselling
     * race between two concurrent Zapisy for the same Dystans.
     *
     * @return array<string, mixed>
     */
    private function lockDistanceForRegistration(string $eventId, string $distanceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.name AS eventName, e.date AS eventDate, e.payment_config AS paymentConfig,
                    d.id AS distanceId, d.name AS distanceName,
                    d.price AS distancePrice, d.registered_count AS registeredCount,
                    d.participant_limit AS participantLimit, d.price_tiers AS priceTiers,
                    d.start_type AS startType, d.relay_min_team_size AS relayMinTeamSize,
                    d.relay_max_team_size AS relayMaxTeamSize, d.special_categories AS specialCategories,
                    d.team_pricing_mode AS teamPricingMode
             FROM events e INNER JOIN event_distances d ON d.event_id = e.id
             WHERE e.id = :eventId AND d.id = :distanceId
             FOR UPDATE'
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('registration_distance_invalid');
        }

        return $row;
    }

    /**
     * Seat-capacity limit (rozstrzygnięcie 4: "limit miejsc na Dystansie") — a null
     * or zero `participant_limit` means unlimited. Checked against the locked
     * row's registeredCount, so this can never observe a stale count from a
     * concurrent Zapis still in flight.
     *
     * @param array<string, mixed> $distanceRow
     */
    private function enforceCapacity(array $distanceRow): void
    {
        $limit = $distanceRow['participantLimit'] !== null ? (int) $distanceRow['participantLimit'] : null;
        if ($limit !== null && $limit > 0 && (int) $distanceRow['registeredCount'] >= $limit) {
            throw new \RuntimeException('registration_capacity_exceeded');
        }
    }

    /**
     * E7.1 (issue 23 Answer §1): resolves what this new Zapis's
     * `team_id`/`team_name` should actually be, formalizing what used to be
     * two free-text fields the client could set to anything.
     *
     * Two real, server-validated intents, both keyed off the same
     * `teams` entity (TeamRepository):
     *  - `data['teamId']` set — JOINING an existing Drużyna. Must exist,
     *    PP.54: the joiner must present the team's `teamInviteToken` or be
     *    the captain (`actorUserId`) — 403-class `registration_team_invite_required`
     *    otherwise (team ids are public, so an id alone is no invitation),
     *    must belong to this exact event+distance, and must not already be
     *    at `relay_max_team_size` ("team full" — a distinct, legitimate
     *    rejection, not a capacity/limit error: the Dystans' own limit,
     *    E1.3, is completely unaffected by a team being full).
     *  - `data['createTeam']` truthy (no `teamId`) — CREATING a brand-new
     *    Drużyna out of this Zapis. Only legal on a `start_type='relay'`
     *    Dystans (Answer §1's roster concept doesn't exist on an individual
     *    Dystans) — rejected otherwise.
     *
     * Anything else (no `teamId`, no `createTeam`) is the pre-existing,
     * UNCHANGED "za drużynę" classification label some organizers collect
     * on ANY Dystans via the general `collectTeam` form option (E1.13) —
     * plain free text, no roster, no size limit, no `teams` row, exactly as
     * it worked before this ticket. This is deliberate: E7.1 only formalizes
     * the relay roster case (issue 23), not that unrelated general-purpose
     * team-name-for-classification feature, which `ResultRepository::
     * teamClassification()` (E2.7) and the public start list already read
     * straight off `registrations.team_name` regardless of whether a real
     * `teams` row exists behind it.
     *
     * Runs after enforceCapacity()/inside the still-locked Dystans row (see
     * create()) — every check here (team full, in particular) is therefore
     * already serialized against every other concurrent Zapis for the same
     * Dystans, with no separate lock needed on `teams` itself.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $distanceRow
     * @return array{teamId: ?string, teamName: ?string}
     */
    private function resolveTeamForRegistration(array $data, array $distanceRow): array
    {
        $teams = new TeamRepository($this->pdo);

        $teamId = isset($data['teamId']) ? trim((string) $data['teamId']) : '';
        if ($teamId !== '') {
            $team = $teams->find($teamId);
            if ($team === null) {
                throw new \RuntimeException('registration_team_not_found');
            }
            // PP.54 (spec 23 §1): an existing Drużyna is joined only through the captain's invitation
            // link or by the captain themself (captain consent). `actorUserId` is set by the HTTP layer
            // from the JWT, never from the body (RegistrationCreatePolicy drops it); internal callers that
            // omit it fall back to the invitation check only, i.e. fail closed.
            $actorUserId = is_string($data['actorUserId'] ?? null) ? trim($data['actorUserId']) : '';
            $presentedToken = is_string($data['teamInviteToken'] ?? null) ? trim($data['teamInviteToken']) : '';
            if (!TeamRepository::isCaptain($team, $actorUserId)
                && !$teams->inviteTokenMatches((string) $team['id'], $presentedToken)) {
                throw new \RuntimeException('registration_team_invite_required');
            }
            if ((string) $team['eventId'] !== (string) $data['eventId'] || (string) $team['distanceId'] !== (string) $data['distanceId']) {
                throw new \RuntimeException('registration_team_distance_mismatch');
            }
            $maxSize = $distanceRow['relayMaxTeamSize'] !== null ? (int) $distanceRow['relayMaxTeamSize'] : null;
            if ($maxSize !== null && $team['memberCount'] >= $maxSize) {
                throw new \RuntimeException('registration_team_full');
            }

            return ['teamId' => $team['id'], 'teamName' => $team['name']];
        }

        if (!empty($data['createTeam'])) {
            if ((string) ($distanceRow['startType'] ?? 'individual') !== 'relay') {
                throw new \RuntimeException('registration_team_requires_relay_distance');
            }
            $name = trim((string) ($data['teamName'] ?? ''));
            if ($name === '') {
                throw new \RuntimeException('registration_team_name_required');
            }
            // E7.5 (issue 23 Answer §7): the captain's optional, manual
            // Kategoria specjalna declaration, validated here against the
            // SAME configured list ticket 10's individual-registration
            // `category` free text is offered from (never against the
            // roster — this ticket's whole point is that it must NOT be
            // derived from who's on the team).
            $specialCategoryRaw = isset($data['specialCategory']) ? trim((string) $data['specialCategory']) : '';
            $specialCategory = $specialCategoryRaw !== '' ? $specialCategoryRaw : null;
            TeamRepository::validateSpecialCategory(
                $specialCategory,
                TeamRepository::decodeSpecialCategories($distanceRow['specialCategories'] ?? null)
            );
            $team = $teams->create([
                'eventId' => $data['eventId'],
                'distanceId' => $data['distanceId'],
                'name' => $name,
                'createdByUserId' => $data['userId'] ?? null,
                'specialCategory' => $specialCategory,
            ]);

            return ['teamId' => $team['id'], 'teamName' => $team['name']];
        }

        // Legacy/general classification path — unchanged.
        return ['teamId' => null, 'teamName' => $data['teamName'] ?? null];
    }

    /**
     * Atomic, affected-rows-guarded increment — the write-side half of the
     * transactional counter (the read-side half is lockDistanceForRegistration()
     * + enforceCapacity()). The WHERE clause is redundant with the row lock
     * already held by the caller under normal operation, but keeps the guarantee
     * even if this method is ever called without that lock, and matches the
     * ticket's "affected-rows check" pattern.
     */
    private function incrementRegisteredCount(string $distanceId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE event_distances
             SET registered_count = registered_count + 1
             WHERE id = :id AND (participant_limit IS NULL OR participant_limit <= 0 OR registered_count < participant_limit)'
        );
        $stmt->execute(['id' => $distanceId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('registration_capacity_exceeded');
        }
    }

    /**
     * Mirror of incrementRegisteredCount() for a Zapis leaving the counted
     * population (cancel/remove) — see updateStatus(). Clamped at 0 defensively;
     * it should never go negative given the counter starts consistent
     * (033_e13 backfill) and only ever decrements once per Zapis via
     * updateStatus()'s old-status check, but a floor is cheap insurance against
     * any future write path this ticket didn't anticipate.
     */
    private function decrementRegisteredCount(string $distanceId): void
    {
        $this->pdo->prepare(
            'UPDATE event_distances SET registered_count = GREATEST(0, registered_count - 1) WHERE id = :id'
        )->execute(['id' => $distanceId]);
    }

    /**
     * E1.4/TD.7: the frozen payment-window deadline for a brand-new pending Zapis —
     * `registered_at` (now) plus the event's configured window for whichever route
     * this event's `paymentConfig.type` actually uses: the (short) gateway window
     * for trasa A, or the (longer, day-granularity) transfer window for trasa B
     * (`organizer_bank`, the same check `paymentConfigType()` makes, inlined here
     * to avoid its extra query — see below). Reads the event's `payment_config`
     * straight off the already-locked Dystans row (lockDistanceForRegistration()
     * joins it in), so this never issues an extra query and can't observe a
     * different row than the one create() just priced against.
     *
     * @param array<string, mixed> $distanceRow
     */
    private function paymentExpiryDeadline(array $distanceRow): string
    {
        $raw = $distanceRow['paymentConfig'] ?? null;
        $paymentConfig = is_string($raw) && trim($raw) !== '' ? (json_decode($raw, true) ?: []) : [];
        $type = is_array($paymentConfig) ? (string) ($paymentConfig['type'] ?? '') : '';

        if ($type === 'organizer_bank') {
            $days = is_array($paymentConfig) ? (int) ($paymentConfig['transferWindowDays'] ?? 0) : 0;
            if ($days <= 0) {
                $days = self::DEFAULT_TRANSFER_WINDOW_DAYS;
            }

            return (new \DateTimeImmutable())->modify("+{$days} days")->format(DATE_ATOM);
        }

        $minutes = is_array($paymentConfig) ? (int) ($paymentConfig['gatewayWindowMinutes'] ?? 0) : 0;
        if ($minutes <= 0) {
            $minutes = self::DEFAULT_GATEWAY_WINDOW_MINUTES;
        }

        return (new \DateTimeImmutable())->modify("+{$minutes} minutes")->format(DATE_ATOM);
    }

    /**
     * Lazy `pending` -> `expired` transition (E1.4), the same delete/finish-on-read
     * pattern as AdminRepository::pruneExpiredActivityLogs() (E0.8) and
     * EventRepository::lazilyFinishPastEvents() (E1.1) — no cron/queue
     * infrastructure exists in this codebase, so every read path (and create(),
     * so a new Zapis never has to fight an already-expired one for the same slot)
     * runs this first. Each expiry is its own small transaction (expireRegistration())
     * rather than one bulk UPDATE, because — unlike lazilyFinishPastEvents(), which
     * only ever touches the `events` row it's updating — this must also release the
     * Dystans slot the same transactional way E1.3's cancel path does, which needs
     * the Dystans row locked per-registration, not once for the whole batch.
     */
    private function lazilyExpireUnpaidRegistrations(): void
    {
        $this->sendPaymentReminders();

        $stmt = $this->pdo->prepare(
            "SELECT id FROM registrations
             WHERE status = 'pending' AND payment_expires_at IS NOT NULL AND payment_expires_at < :now"
        );
        $stmt->execute(['now' => date(DATE_ATOM)]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->expireRegistration((string) $id);
        }
    }

    /**
     * E1.10 mail #7 ("Przypomnienie o nieopłaconym zapisie", §5.16). Same lazy,
     * no-cron trigger shape as lazilyExpireUnpaidRegistrations() itself (this
     * repo has none — see that method's doc comment) — called from the same
     * spot, once per read, before expiry is checked, so a registration gets one
     * last chance to be reminded before it lapses. Never sends twice for the
     * same registration: CriticalMailer's idempotency key is
     * `{registrationId}:unpaid_reminder:v{version}`, so a repeat call within
     * the reminder window is a guaranteed outbox no-op (see
     * CentralMailerOutboxService::enqueue()), not a duplicate mail — no extra
     * "already reminded" column needed.
     */
    private const REMINDER_WINDOW_MINUTES = 15;

    private function sendPaymentReminders(): void
    {
        if ($this->mailer === null) {
            return;
        }

        $now = new \DateTimeImmutable();
        $windowEnd = $now->modify('+' . self::REMINDER_WINDOW_MINUTES . ' minutes')->format(DATE_ATOM);
        $stmt = $this->pdo->prepare(
            "SELECT id, event_id AS eventId, event_name AS eventName, user_email AS userEmail, payment_expires_at AS paymentExpiresAt
             FROM registrations
             WHERE status = 'pending' AND payment_expires_at IS NOT NULL
               AND payment_expires_at >= :now AND payment_expires_at < :windowEnd"
        );
        $stmt->execute(['now' => $now->format(DATE_ATOM), 'windowEnd' => $windowEnd]);

        foreach ($stmt->fetchAll() as $row) {
            $this->mailer->sendUnpaidReminder(
                (string) $row['id'],
                (string) $row['eventId'],
                (string) $row['userEmail'],
                (string) $row['eventName'],
                number_format($this->totalAmountFor((string) $row['id']), 2, '.', ''),
                rtrim((string) \ZmierzymyCzas\Support\Env::get('APP_URL', ''), '/') . '/registrations/' . $row['id'] . '/pay',
                (string) $row['paymentExpiresAt']
            );
        }
    }

    /**
     * Expires exactly one Zapis, transactionally: locks the row, re-checks under
     * the lock that it is still `pending` and still past its deadline (guards both
     * a concurrent expireRegistration() for the same id — the second one's lock
     * wait ends with nothing left to do — and this method simply being called
     * twice for the same id, e.g. from two lazy checks racing on different read
     * paths), then flips the status and decrements the Dystans counter exactly
     * once, the same way updateStatus() does for a manual cancel.
     */
    private function expireRegistration(string $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT status, distance_id AS distanceId, payment_expires_at AS paymentExpiresAt
                 FROM registrations WHERE id = :id FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $current = $stmt->fetch();
            $now = date(DATE_ATOM);
            if (
                $current === false
                || $current['status'] !== 'pending'
                || $current['paymentExpiresAt'] === null
                || (string) $current['paymentExpiresAt'] >= $now
            ) {
                $this->pdo->rollBack();
                return;
            }

            $updated = $this->pdo->prepare("UPDATE registrations SET status = 'expired' WHERE id = :id AND status = 'pending'");
            $updated->execute(['id' => $id]);
            if ($updated->rowCount() === 1) {
                $this->decrementRegisteredCount((string) $current['distanceId']);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * E1.3: status changes cross the counted/not-counted boundary defined by
     * NOT_COUNTED_STATUSES — a cancel (the only such path that exists in this
     * repo today, per update()/cancel()) must decrement, and — since update()
     * accepts an arbitrary status and nothing here invents a "reactivate" feature
     * that doesn't otherwise exist — a hypothetical future move back out of
     * cancelled/removed correctly re-increments (and re-checks capacity) rather
     * than silently leaving the counter permanently short.
     *
     * @param array<string, mixed> $mapped
     */
    private function updateStatus(string $id, string $newStatus, array $mapped): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status, distance_id AS distanceId FROM registrations WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $current = $stmt->fetch();
            if ($current === false) {
                $this->pdo->rollBack();
                return;
            }

            $oldStatus = (string) $current['status'];
            $wasCounted = !in_array($oldStatus, self::NOT_COUNTED_STATUSES, true);
            $isCounted = !in_array($newStatus, self::NOT_COUNTED_STATUSES, true);

            if (!$wasCounted && $isCounted) {
                // Re-entering the counted population: lock+check the Dystans the same
                // way create() does, so this can't push the count past capacity either.
                $distanceRow = $this->lockDistanceForRegistration(
                    (string) $this->registrationEventId($id),
                    (string) $current['distanceId']
                );
                $this->enforceCapacity($distanceRow);
            }

            $this->updateById('registrations', $id, $mapped);

            if ($wasCounted && !$isCounted) {
                $this->decrementRegisteredCount((string) $current['distanceId']);
            } elseif (!$wasCounted && $isCounted) {
                $this->incrementRegisteredCount((string) $current['distanceId']);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): ?array
    {
        $mapped = [];
        foreach ([
            'status' => 'status',
            'bibNumber' => 'bib_number',
            'teamName' => 'team_name',
            'teamId' => 'team_id',
            'discountCode' => 'discount_code',
            'removedAt' => 'removed_at',
            'checkedInAt' => 'checked_in_at',
            'checkedInBy' => 'checked_in_by',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        if ($mapped !== []) {
            // E3.5 (issue 12 §2): any write through this single choke-point counts as
            // "the platform touched this Zapis" for BZ return-sync LWW comparisons —
            // see applyBzFieldSync()'s own docblock and migration 050. `bibNumber` is
            // the one BZ-in-scope field with a real write path through this method
            // today; `category`/`contact_email` (user_email) have none yet, so this
            // stamp is presently a no-op for them until some future edit path routes
            // through here too — that day needs no further plumbing.
            $mapped['updated_at'] = date(DATE_ATOM);
        }
        $wasCancelledAlready = null;
        if (array_key_exists('status', $mapped) && (string) $mapped['status'] === 'cancelled') {
            // Read before the transition, not after: this is the only way to tell
            // a genuine "organizer just cancelled this" edge from a repeated call
            // (e.g. two clicks, or a retried request) against an already-cancelled
            // Zapis — needed below for the E3.4 webhook push, which must fire once
            // per real cancellation, not once per call.
            $before = $this->find($id);
            $wasCancelledAlready = $before !== null ? ((string) ($before['status'] ?? '') === 'cancelled') : null;
        }
        if (array_key_exists('status', $mapped)) {
            $this->updateStatus($id, (string) $mapped['status'], $mapped);
        } elseif ($mapped !== []) {
            $this->updateById('registrations', $id, $mapped);
        }
        if (array_key_exists('merchandise', $data) || array_key_exists('merchandiseOrders', $data)) {
            // Krok 5 (Gadżety): nawet na dopisanie do istniejącego Zapisu cena×ilość
            // liczy się z katalogu (`event_merchandise`), nigdy z tego, co przyśle klient —
            // patrz resolveMerchandiseLines(). Zamrożone pozycje Rozliczenia (ticket 20:
            // przeliczenie salda) tym się nie zajmuje, tylko sama tabela zamówień.
            $eventId = (string) ($this->registrationEventId($id) ?? '');
            $lines = $data['merchandise'] ?? $data['merchandiseOrders'] ?? [];
            $this->replaceMerchandise($id, $this->resolveMerchandiseLines($eventId, $lines)['orders']);
        }
        if (array_key_exists('paymentDetails', $data)) {
            $this->upsertPaymentDetails($id, $data['paymentDetails'] ?? []);
        }

        $result = $this->find($id);

        // E3.4: "rezygnacja" trigger. Fires for every path that lands a Zapis on
        // `cancelled` through this method — the single-registration organizer
        // cancel route (cancel(), below) and any future direct PATCH doing the
        // same — but deliberately NOT for markRefunded()'s own full-refund ->
        // cancelled side effect (that one is a consequence of a refund, reported
        // as a refund_refunded push instead, not a second, redundant
        // cancellation notification — see markRefunded()'s call site).
        if ($wasCancelledAlready === false && $result !== null && (string) ($result['status'] ?? '') === 'cancelled') {
            $this->pushBzCriticalChange((string) $result['eventId'], $id, 'cancellation');
        }

        return $result;
    }

    public function cancel(string $id): ?array
    {
        return $this->update($id, ['status' => 'cancelled']);
    }

    /**
     * Records Odprawa (check-in) against this Zapis. This is not the full Odprawa
     * model (Biuro Zawodów, separate ticket) — just enough to not lose "czy i kiedy
     * sprawdzono zapis" now that Bilet is a view of Zapis, not a separate entity.
     */
    public function checkIn(string $id): ?array
    {
        return $this->update($id, ['checkedInAt' => date(DATE_ATOM)]);
    }

    /** @param array<string, mixed> $paymentDetails */
    public function updatePaymentDetails(string $id, array $paymentDetails): ?array
    {
        $this->upsertPaymentDetails($id, $paymentDetails);
        return $this->find($id);
    }

    /**
     * E1.5, spec 13 rozstrzygnięcie 1: Zawodnik never triggers a refund directly —
     * this only ever creates a *request*, which an organizer (own Wydarzenie) or
     * admin must separately approveRefund(). Only valid from a `confirmed`
     * (opłacona) Zapis. Idempotent (point 6): calling again while a cycle is
     * already `requested`/`approved` is a no-op that returns the current state; a
     * *new* request is only accepted once the previous cycle finished `refunded`
     * (supports a second, later, independent partial refund on the same Zapis).
     */
    public function requestRefund(string $id, string $requestedBy, ?string $reason = null): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $registration = $this->lockRegistration($id);
            if ($registration === null) {
                $this->pdo->rollBack();
                return null;
            }
            if ($registration['status'] !== 'confirmed') {
                throw new \RuntimeException('refund_requires_paid_registration');
            }

            $existing = $this->lockRefund($id);
            if ($existing !== null && $existing['status'] !== 'refunded') {
                $this->pdo->commit();
                return $this->find($id);
            }

            $this->upsertRefundRow($id, [
                'status' => 'requested',
                'requested_at' => date(DATE_ATOM),
                'requested_by' => $requestedBy,
                'reason' => $reason,
                'approved_at' => null,
                'approved_by' => null,
                'amount' => null,
                'route' => null,
                'completed_at' => null,
                'completed_by' => null,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * Organizator (own Wydarzenie) or admin approves a pending refund request —
     * spec's "do zwrotu". `$amount` is the saldo to refund (rozstrzygnięcie 3: same
     * arithmetic mechanism for a full cancellation and a partial refund); when
     * omitted it defaults to the whole frozen Rozliczenie total (full refund), and
     * is always clamped to that total as a ceiling regardless of what is passed —
     * a refund can never exceed what was actually collected. Idempotent: approving
     * an already-`approved` cycle is a no-op.
     */
    public function approveRefund(string $id, string $approvedBy, ?float $amount = null): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $registration = $this->lockRegistration($id);
            if ($registration === null) {
                $this->pdo->rollBack();
                return null;
            }
            $existing = $this->lockRefund($id);
            if ($existing === null || $existing['status'] === 'refunded') {
                throw new \RuntimeException('refund_request_not_found');
            }
            if ($existing['status'] === 'approved') {
                $this->pdo->commit();
                return $this->find($id);
            }

            $ceiling = $this->totalAmountFor($id);
            $resolvedAmount = $amount !== null ? max(0.0, min($amount, $ceiling)) : $ceiling;
            $route = $this->refundRouteForRegistration($registration);

            $this->pdo->prepare(
                "UPDATE registration_refunds
                 SET status = 'approved', approved_at = :approvedAt, approved_by = :approvedBy, amount = :amount, route = :route
                 WHERE registration_id = :id AND status = 'requested'"
            )->execute([
                'approvedAt' => date(DATE_ATOM),
                'approvedBy' => $approvedBy,
                'amount' => $this->roundMoney($resolvedAmount),
                'route' => $route,
                'id' => $id,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // E1.10 mail #14 ("Zwrot pieniędzy zatwierdzony") — the "do zwrotu"
        // transition itself, sent once per approval (not repeated at
        // markRefunded()'s later "zwrócona" transition — one notification per
        // refund cycle is enough, and the amount/route are already final here).
        // Deliberately NOT sent from bulkApproveOneRefund() (event cancellation):
        // that flow sends mail #8 instead, which already carries refund status
        // inline for every affected registrant — see routes.php's cancel handler.
        $this->sendRefundApprovedMail($id, $resolvedAmount, $route);

        // E1.6/E1.7: trasa A really calls the gateway's (Tpay or PayNow, whichever
        // took the original payment) refund API now, once the "do zwrotu" approval
        // itself is safely committed — same "never hold the row lock across an
        // outbound HTTP call" reasoning as startGatewaySessionIfNeeded(). Trasa B
        // stays exactly as before (organizator marks it done by hand later).
        $gatewayRefundFailed = false;
        if ($route === 'gateway') {
            $gatewayRefundFailed = !$this->initiateGatewayRefund($id);
        }

        // E3.4: "zwrot" trigger, "do zwrotu" sub-state — one push per approval,
        // same "runs only on the genuine fresh transition" guarantee as
        // sendRefundApprovedMail() just above (the early-return for an
        // already-`approved` cycle never reaches this line).
        $this->pushBzCriticalChange((string) $registration['eventId'], $id, 'refund_approved');

        $result = $this->find($id);
        if ($gatewayRefundFailed && $result !== null && isset($result['refund']) && is_array($result['refund'])) {
            // TD.13: the approval itself did commit (see docblock on
            // initiateGatewayRefund() for why that's deliberately not rolled
            // back), but the caller must not get a plain 200 that reads as
            // "refund succeeded end to end" when the actual gateway call
            // failed — this is a response-only, non-persisted flag, not a new
            // refund-state-machine state.
            $result['refund']['gatewayRefundError'] = true;
        }

        return $result;
    }

    /**
     * Executes the terminal `approved` -> `refunded` transition. Route B (przelew
     * tradycyjny) is fully manual and this IS the real "organizator odznacza
     * zwrócono" action for it — in scope per the ticket. Route A (Tpay or PayNow)
     * now really does call the gateway's refund API from approveRefund() (E1.6/
     * E1.7, see initiateGatewayRefund()) — but both gateways confirm a refund
     * asynchronously via their own notification, and wiring that specific
     * notification straight into this method is E1.10 (mail) territory sharing
     * the same webhook, so today this action still doubles as the admin's manual
     * bookkeeping confirmation once they see the refund landed on the gateway's
     * side. Idempotent: marking an
     * already-`refunded` cycle refunded again is a no-op. When the refunded amount
     * covers the whole frozen Rozliczenie (a full refund, not a partial one), the
     * Zapis itself also moves to `cancelled` — rozstrzygnięcie: registrations.status
     * must stay consistent with the refund state machine, never a second, silently
     * diverging source of truth (a partial refund leaves the Zapis `confirmed`,
     * since the registrant is still attending).
     */
    public function markRefunded(string $id, string $completedBy): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $registration = $this->lockRegistration($id);
            if ($registration === null) {
                $this->pdo->rollBack();
                return null;
            }
            $existing = $this->lockRefund($id);
            if ($existing === null) {
                throw new \RuntimeException('refund_request_not_found');
            }
            if ($existing['status'] === 'refunded') {
                $this->pdo->commit();
                return $this->find($id);
            }
            if ($existing['status'] !== 'approved') {
                throw new \RuntimeException('refund_not_approved');
            }

            $this->pdo->prepare(
                "UPDATE registration_refunds
                 SET status = 'refunded', completed_at = :completedAt, completed_by = :completedBy
                 WHERE registration_id = :id AND status = 'approved'"
            )->execute([
                'completedAt' => date(DATE_ATOM),
                'completedBy' => $completedBy,
                'id' => $id,
            ]);

            $refundedAmount = (float) $existing['amount'];
            $ceiling = $this->totalAmountFor($id);
            $isFullRefund = $refundedAmount >= ($ceiling - 0.005);
            if ($isFullRefund && $registration['status'] === 'confirmed') {
                $cancelled = $this->pdo->prepare(
                    "UPDATE registrations SET status = 'cancelled' WHERE id = :id AND status = 'confirmed'"
                );
                $cancelled->execute(['id' => $id]);
                if ($cancelled->rowCount() === 1) {
                    $this->decrementRegisteredCount((string) $registration['distanceId']);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // E3.4: "zwrot" trigger, terminal "zwrócono" sub-state — one push per
        // completed refund cycle (the early-return for an already-`refunded`
        // cycle above never reaches this line). Deliberately reported as
        // `refund_refunded`, not also as a `cancellation` push, even when this
        // was a full refund that just moved the Zapis to `cancelled` above —
        // that transition is a side effect of the refund, not an independent
        // organizer cancellation, so BZ gets one notification for it, not two.
        $this->pushBzCriticalChange((string) $registration['eventId'], $id, 'refund_refunded');

        return $this->find($id);
    }

    /**
     * Route A (Tpay or PayNow) — the real gateway refund call E1.5 deliberately
     * left this seam empty for. Finds the Zapis's most recently `paid` gateway
     * transaction and asks whichever gateway processed it (its `gateway` column,
     * see below) to refund it for the approved refund amount
     * (registration_refunds.amount — never anything else, same "amount only
     * ever comes from the frozen Rozliczenie/refund cycle, not the caller" rule
     * as everywhere else in this class). On the gateway's own confirmation (a
     * refund/chargeback notification, delivered through the same webhook as a
     * payment — TpayNotification/PayNowNotification) that integration is
     * expected to call markRefunded() itself, the same terminal transition a
     * route B organizer triggers by hand today; wiring that specific
     * notification-to-markRefunded() call is left to E1.10 territory (mail
     * seam) alongside it, not duplicated here.
     *
     * No-op (not an error) when there is no gateway configured for the paying
     * provider or no paid transaction on file — mirrors
     * startGatewaySessionIfNeeded()'s "never let a gateway hiccup block a state
     * transition that already happened on our side" stance; approveRefund()/
     * markRefunded() remain the source of truth for whether a refund is
     * recorded as done on this platform.
     *
     * TD.13: a thrown exception from $gateway->refund() itself is still not
     * allowed to unwind approveRefund() (same reasoning as above — the
     * platform-side `approved` state must not flip-flop on a gateway hiccup,
     * and there is no `failed` state in migration 035's refund state machine
     * to move it to instead), but it must no longer vanish without a trace:
     * it is now logged (registration id, amount, gateway, transaction id,
     * exception) the same way ChallengeRepository::isChallengeCompletedForUser()
     * logs a swallowed \RuntimeException, and the boolean return here lets
     * approveRefund() mark the response honestly instead of a silent 200.
     * Actually retrying the gateway call is still an operational follow-up
     * (no retry endpoint exists yet) — out of scope for this fix.
     *
     * @return bool false only when a paid transaction and a configured
     *     gateway both existed and the actual refund() call to it threw.
     */
    public function initiateGatewayRefund(string $id): bool
    {
        $refund = $this->refund($id);
        if ($refund === null || $refund['amount'] === null) {
            return true;
        }

        // The provider that actually took the payment — read off the paid
        // transaction row itself (frozen at session-creation time), never
        // re-derived from the Wydarzenie's *current* payment_config, which may
        // have been reconfigured to a different provider since this Zapis paid.
        $stmt = $this->pdo->prepare(
            "SELECT transaction_id AS transactionId, gateway FROM registration_gateway_transactions
             WHERE registration_id = :id AND status = 'paid' ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $transaction = $stmt->fetch();
        if ($transaction === false) {
            return true;
        }
        $transactionId = (string) $transaction['transactionId'];
        if ($transactionId === '') {
            return true;
        }

        $gatewayProvider = (string) $transaction['gateway'];
        $gateway = $this->gatewayForProvider($gatewayProvider);
        if ($gateway === null) {
            return true;
        }

        try {
            $gateway->refund($transactionId, (float) $refund['amount']);
        } catch (\Throwable $e) {
            // TD.13: was silently swallowed — a failed gateway refund call had
            // no log, no alert, nothing beyond a customer complaint. The
            // platform-side `approved` state is deliberately left as-is (see
            // docblock above), but the failure itself must be investigable.
            $this->logger->error(sprintf(
                '[RegistrationRepository::initiateGatewayRefund] Gateway refund call failed for registration %s '
                . '(transactionId=%s, gateway=%s, amount=%s): %s: %s',
                $id,
                $transactionId,
                $gatewayProvider,
                (string) $refund['amount'],
                get_class($e),
                $e->getMessage()
            ), [
                'registrationId' => $id,
                'transactionId' => $transactionId,
                'gateway' => $gatewayProvider,
                'amount' => (string) $refund['amount'],
                'exception' => $e,
                'alert' => 'payments.refund_failed',
            ]);

            return false;
        }

        $this->logger->info('Gateway refund requested', [
            'registrationId' => $id,
            'transactionId' => $transactionId,
            'gateway' => $gatewayProvider,
            'amount' => (string) $refund['amount'],
        ]);

        return true;
    }

    /**
     * "Ponowienie" (spec: „nie wiem jak zapłacić" — jednoklikowe ponowienie
     * płatności): reopens a fresh `pending` state with a brand-new payment window,
     * for a Zapis that has already `expired`. Deliberately does not call any
     * gateway (E1.6/E1.7 out of scope, rozstrzygnięcie 8) — it only re-establishes
     * the same slot-holding state a brand-new Zapis would have, ready for whichever
     * gateway integration lands later to hang its "start new session" call off.
     * Re-checks Dystans capacity under the same row lock create() uses, since the
     * slot may have been taken by someone else while this one sat expired.
     */
    public function retryPayment(string $id): ?array
    {
        $this->lazilyExpireUnpaidRegistrations();
        $this->pdo->beginTransaction();
        try {
            $registration = $this->lockRegistration($id);
            if ($registration === null) {
                $this->pdo->rollBack();
                return null;
            }
            if ($registration['status'] !== 'expired') {
                throw new \RuntimeException('retry_requires_expired_registration');
            }

            $distanceRow = $this->lockDistanceForRegistration((string) $registration['eventId'], (string) $registration['distanceId']);
            $this->enforceCapacity($distanceRow);

            $newExpiry = $this->paymentExpiryDeadline($distanceRow);
            $updated = $this->pdo->prepare(
                "UPDATE registrations SET status = 'pending', payment_expires_at = :expiresAt WHERE id = :id AND status = 'expired'"
            );
            $updated->execute(['expiresAt' => $newExpiry, 'id' => $id]);
            if ($updated->rowCount() === 1) {
                $this->incrementRegisteredCount((string) $distanceRow['distanceId']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // E1.6/E1.7: "ponowienie" now really does hang a fresh gateway session off the
        // re-opened `pending` state, per the ticket — the seam retryPayment()'s
        // own docblock (above) was left waiting for.
        $registration = $this->find($id);
        if ($registration !== null && $registration['status'] === 'pending') {
            $registration['paymentSession'] = $this->startGatewaySessionIfNeeded($registration);
        }

        return $registration;
    }

    /**
     * Bulk trigger for "odwołanie Wydarzenia" (spec rozstrzygnięcie 2): every
     * `confirmed` Zapis of the Wydarzenie is marked straight to `approved` ("do
     * zwrotu") in one action — skipping the `requested` step entirely, since an
     * organizer/admin cancelling their own event is already the approving party,
     * there is no separate zawodnik request to wait for. Safe to call more than
     * once (e.g. a defensive re-trigger): a Zapis already mid-cycle or already
     * `refunded` is left untouched. Returns how many Zapisy were newly marked.
     */
    public function bulkApproveRefundsForEvent(string $eventId, string $approvedBy, string $reason = 'event_cancelled'): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM registrations WHERE event_id = :eventId AND status = 'confirmed'");
        $stmt->execute(['eventId' => $eventId]);

        $count = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            if ($this->bulkApproveOneRefund((string) $id, $approvedBy, $reason)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * E1.10 note: deliberately does not call sendRefundApprovedMail() (unlike
     * the public approveRefund() above) — event cancellation sends mail #8 to
     * every registrant instead, which already states the refund status inline;
     * a second, separate mail #14 per registrant here would be redundant noise.
     */
    private function bulkApproveOneRefund(string $id, string $approvedBy, string $reason): bool
    {
        $this->pdo->beginTransaction();
        try {
            $registration = $this->lockRegistration($id);
            if ($registration === null || $registration['status'] !== 'confirmed') {
                $this->pdo->rollBack();
                return false;
            }
            $existing = $this->lockRefund($id);
            if ($existing !== null && $existing['status'] !== 'refunded') {
                $this->pdo->commit();
                return false;
            }

            $route = $this->refundRouteForRegistration($registration);
            $this->upsertRefundRow($id, [
                'status' => 'approved',
                'requested_at' => null,
                'requested_by' => null,
                'reason' => $reason,
                'approved_at' => date(DATE_ATOM),
                'approved_by' => $approvedBy,
                'amount' => $this->roundMoney($this->totalAmountFor($id)),
                'route' => $route,
                'completed_at' => null,
                'completed_by' => null,
            ]);
            $this->pdo->commit();
            if ($route === 'gateway') {
                $this->initiateGatewayRefund($id);
            }
            // E3.4: same "do zwrotu" push as the single-registration
            // approveRefund() above — an organizer cancelling their whole
            // Wydarzenie is still, per-registration, exactly the "zwrot"
            // critical change BZ needs to hear about (issue 12 §1 explicitly
            // frames the push as mattering most "blisko wydarzenia", which is
            // exactly when a bulk event cancellation happens); only the mail
            // side (sendRefundApprovedMail(), see this method's doc comment)
            // is skipped here to avoid double-notifying the registrant.
            $this->pushBzCriticalChange((string) $registration['eventId'], $id, 'refund_approved');
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Locks a Zapis row for a refund-cycle transition — same "lock, re-check under
     * the lock" pattern as expireRegistration()/updateStatus(), just scoped to the
     * columns the refund methods above actually need.
     *
     * @return array<string, mixed>|null
     */
    private function lockRegistration(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, event_id AS eventId, distance_id AS distanceId FROM registrations WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function lockRefund(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_refunds WHERE registration_id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Upsert on the single-row-per-Zapis refund table (INSERT .. ON DUPLICATE KEY
     * UPDATE, not a delete+replace like upsertPaymentDetails() — a refund cycle's
     * history must not vanish on an unrelated write). Must be called from inside a
     * transaction that already holds the row lock (lockRefund()) when updating an
     * existing cycle, so a concurrent transition can't interleave.
     *
     * @param array<string, mixed> $fields
     */
    private function upsertRefundRow(string $registrationId, array $fields): void
    {
        $columns = ['registration_id', ...array_keys($fields)];
        $updates = implode(', ', array_map(static fn (string $column): string => "{$column} = VALUES({$column})", array_keys($fields)));
        $sql = sprintf(
            'INSERT INTO registration_refunds (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)),
            $updates
        );
        $this->pdo->prepare($sql)->execute(['registration_id' => $registrationId, ...$fields]);
    }

    /** The frozen Rozliczenie's DO ZAPŁATY total — the refund ceiling. */
    /**
     * DO ZAPŁATY of one Rozliczenie: sum of its items, floored at 0 (same rule as hydrate()).
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function amountDueFromItems(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) $item['amount'];
        }

        return $this->roundMoney(max(0, $total));
    }

    private function totalAmountFor(string $registrationId): float
    {
        return $this->amountDueFromItems($this->settlementItems($registrationId));
    }

    /**
     * Trasa A (bramka) vs trasa B (przelew tradycyjny), read from the Wydarzenie's
     * `payment_config.type` at the moment of approval — frozen onto the refund row
     * so it does not silently change if the organizer later reconfigures payments.
     *
     * @param array<string, mixed> $registration
     */
    private function refundRouteForRegistration(array $registration): string
    {
        return $this->paymentConfigType((string) $registration['eventId']) === 'organizer_bank' ? 'manual' : 'gateway';
    }

    /** Raw `events.payment_config.type` for one Wydarzenie ('organizer_bank' = trasa B; anything else, incl. 'admin_gateway' = trasa A). */
    private function paymentConfigType(string $eventId): string
    {
        $stmt = $this->pdo->prepare('SELECT payment_config AS paymentConfig FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $raw = $stmt->fetchColumn();
        $paymentConfig = is_string($raw) && trim((string) $raw) !== '' ? (json_decode((string) $raw, true) ?: []) : [];

        return is_array($paymentConfig) ? (string) ($paymentConfig['type'] ?? '') : '';
    }

    /**
     * E1.6/E1.7: starts a new gateway session for a fresh `pending` Zapis on
     * trasa A (gdy payment_config.type nie jest 'organizer_bank') and records
     * it in `registration_gateway_transactions`, keyed by the gateway's own
     * transaction id — that key is what makes the webhook idempotent later
     * (applyGatewayNotification()). Which gateway (Tpay or PayNow) is used is
     * resolved once, from `payment_config.provider`
     * (paymentGatewayProviderFor()), and the same name is frozen onto the
     * transaction row so a later refund calls the same provider even if the
     * Wydarzenie is reconfigured afterwards (see initiateGatewayRefund()).
     * The amount is always `totalAmountFor()` — the frozen Rozliczenie's DO
     * ZAPŁATY total — never anything the caller of create()/retryPayment()
     * passed in, per the ticket's hard "klient nigdy nie przysyła kwot"
     * requirement extended to the gateway leg.
     *
     * Returns null (never throws) whenever there is nothing to do: no gateway
     * configured for the selected provider (dev/test/CI — see
     * TpayConfig::isConfigured()/PayNowConfig::isConfigured()), trasa B, a
     * zero-amount Zapis, or the gateway call itself failed — a Zapis must
     * still be creatable/retryable even if the gateway is unreachable; the
     * payment window still governs when it expires either way.
     *
     * @param array<string, mixed> $registration
     * @return array{transactionId: string, redirectUrl: string}|null
     */
    private function startGatewaySessionIfNeeded(array $registration): ?array
    {
        if ($this->paymentConfigType((string) $registration['eventId']) === 'organizer_bank') {
            return null;
        }

        $provider = $this->paymentGatewayProviderFor((string) $registration['eventId']);
        $gateway = $this->gatewayForProvider($provider);
        if ($gateway === null) {
            return null;
        }

        $amount = $this->totalAmountFor((string) $registration['id']);
        if ($amount <= 0) {
            return null;
        }

        try {
            $session = $gateway->startSession(
                (string) $registration['id'],
                $amount,
                'Zapis: ' . (string) $registration['eventName'] . ' — ' . (string) $registration['distanceName'],
                (string) $registration['userEmail'],
                (string) $registration['userName']
            );
        } catch (\Throwable $e) {
            // Gateway unreachable/misconfigured: the Zapis itself was already
            // committed successfully above — never let a gateway hiccup fail
            // the registration. PP.20: but it must leave a trace. Never log the
            // startSession() arguments (user e-mail/name).
            $this->logger->error('Gateway payment session could not be started; registration left pending without a session', [
                'registrationId' => (string) $registration['id'],
                'gateway' => $provider,
                'exception' => $e,
                'alert' => 'payments.session_failed',
            ]);

            return null;
        }

        $this->insert('registration_gateway_transactions', [
            'transaction_id' => $session['transactionId'],
            'registration_id' => (string) $registration['id'],
            'gateway' => $provider,
            'amount' => $this->roundMoney($amount),
            'redirect_url' => $session['redirectUrl'],
            'status' => 'created',
            'created_at' => date(DATE_ATOM),
        ]);

        return $session;
    }

    /**
     * E1.6/E1.7 webhook entry point — apply (or idempotently ignore) one already
     * signature-verified gateway notification (Tpay or PayNow — both webhook
     * routes call this same, provider-agnostic method). `$transactionId`/`$paid`
     * must come from TpayNotification/PayNowNotification, never straight off
     * unverified request data.
     *
     * Paid-amount check (PP.6): `$notifiedAmountGrosze` is the amount the gateway
     * says it collected, in integer grosze (null = the notification reported no
     * usable amount). It is compared, with no tolerance, against the amount frozen
     * in registration_gateway_transactions when the session was started. A paid
     * notification that differs (underpaid, overpaid, or — for a gateway whose
     * notification must carry an amount, i.e. Tpay — missing/unparseable) does NOT
     * mark the payment paid: the row becomes `amount_mismatch`, one PII-free line
     * is logged, and the caller still acks the gateway (the notification is
     * authentic and a retry would carry the same amount). PayNow's notification
     * has no amount field at all, so a null amount from it is exempt (its integrity
     * rests on the HMAC signature, the paymentId primary-key lookup and the frozen
     * amount); a PayNow notification that does carry an amount is compared too.
     *
     * Status machine: `created` -> `paid` | `failed` | `amount_mismatch`;
     * `amount_mismatch` -> `paid` (a later notification with the exact amount, e.g.
     * after a surcharge) | `failed`; `paid`/`failed` are terminal.
     *
     * Idempotency (ticket point 4): the gateway transaction row is looked up and
     * locked by `$transactionId` (its primary key) — a retried webhook for the
     * same transaction finds it already `paid`/`failed` and does nothing further,
     * so a gateway retry-after-timeout can never mark a Rozliczenie paid twice or
     * double-fire whatever side effect eventually hangs off this (E1.10 mail —
     * that seam is the `applied` return value below: true only the one time this
     * call is the one that actually flipped the state to paid/failed). A repeated
     * mismatch is a no-op and is not logged again.
     *
     * @return array{applied: bool, registrationId: ?string, amountMismatch: bool}
     */
    public function applyGatewayNotification(string $transactionId, bool $paid, ?int $notifiedAmountGrosze): array
    {
        $mismatchLog = null;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT transaction_id AS transactionId, registration_id AS registrationId, gateway, status, amount
                 FROM registration_gateway_transactions WHERE transaction_id = :transactionId FOR UPDATE'
            );
            $stmt->execute(['transactionId' => $transactionId]);
            $transaction = $stmt->fetch();
            if ($transaction === false) {
                throw new \RuntimeException('gateway_transaction_unknown');
            }

            $registrationId = (string) $transaction['registrationId'];
            $currentStatus = (string) $transaction['status'];

            if (in_array($currentStatus, ['paid', 'failed'], true)) {
                // Already processed by an earlier delivery of this same
                // notification (or a later one for the same transaction) — the
                // idempotency guarantee itself. Nothing more to do.
                $this->pdo->commit();
                return ['applied' => false, 'registrationId' => $registrationId, 'amountMismatch' => false];
            }

            if (!$paid) {
                // The amount is irrelevant when nothing was paid.
                $this->closeGatewayTransaction($transactionId, 'failed');
                $this->pdo->commit();
                return ['applied' => true, 'registrationId' => $registrationId, 'amountMismatch' => false];
            }

            $expectedGrosze = GatewayAmount::toGrosze((string) $transaction['amount']);
            $mismatch = $expectedGrosze === null
                || ($notifiedAmountGrosze === null
                    ? in_array((string) $transaction['gateway'], self::AMOUNT_REPORTING_GATEWAYS, true)
                    : $notifiedAmountGrosze !== $expectedGrosze);

            if ($mismatch) {
                if ($currentStatus === 'created') {
                    $this->pdo->prepare(
                        "UPDATE registration_gateway_transactions
                         SET status = 'amount_mismatch', processed_at = :processedAt
                         WHERE transaction_id = :transactionId AND status = 'created'"
                    )->execute([
                        'processedAt' => date(DATE_ATOM),
                        'transactionId' => $transactionId,
                    ]);
                    $mismatchLog = [(string) $transaction['gateway'], $expectedGrosze];
                }
                $this->pdo->commit();
                if ($mismatchLog !== null) {
                    $this->logAmountMismatch($transactionId, $registrationId, $mismatchLog[0], $mismatchLog[1], $notifiedAmountGrosze);
                }

                return ['applied' => false, 'registrationId' => $registrationId, 'amountMismatch' => true];
            }

            $this->closeGatewayTransaction($transactionId, 'paid');
            $this->markGatewayPaymentPaid($registrationId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // E1.10 mail #5 ("Potwierdzenie płatności / bilet") — sent only after the
        // `pending` -> `confirmed` transition is safely committed, never from
        // inside markGatewayPaymentPaid() itself (that runs inside this method's
        // transaction; an outbound HTTP call must never happen before a commit
        // that might still roll back — same reasoning as initiateGatewayRefund()
        // being called after approveRefund()'s own commit, below). Never reached
        // for an amount mismatch.
        $this->sendPaymentConfirmedMail($registrationId);

        return ['applied' => true, 'registrationId' => $registrationId, 'amountMismatch' => false];
    }

    /** Moves a still-open (`created` or `amount_mismatch`) gateway transaction row to `paid` or `failed`. */
    private function closeGatewayTransaction(string $transactionId, string $status): void
    {
        $this->pdo->prepare(
            "UPDATE registration_gateway_transactions
             SET status = :status, processed_at = :processedAt
             WHERE transaction_id = :transactionId AND status IN ('created', 'amount_mismatch')"
        )->execute([
            'status' => $status,
            'processedAt' => date(DATE_ATOM),
            'transactionId' => $transactionId,
        ]);
    }

    /** One PII-free line (ids, gateway name and amounts only) so a mismatch is visible in the server log. */
    private function logAmountMismatch(string $transactionId, string $registrationId, string $gateway, ?int $expectedGrosze, ?int $notifiedGrosze): void
    {
        $this->logger->error(sprintf(
            '[RegistrationRepository::applyGatewayNotification] Gateway amount mismatch, payment NOT marked paid: '
            . 'transactionId=%s registrationId=%s gateway=%s expectedGrosze=%s notifiedGrosze=%s',
            $transactionId,
            $registrationId,
            $gateway,
            $expectedGrosze === null ? 'unparseable' : (string) $expectedGrosze,
            $notifiedGrosze === null ? 'missing' : (string) $notifiedGrosze
        ), [
            'transactionId' => $transactionId,
            'registrationId' => $registrationId,
            'gateway' => $gateway,
            'expectedGrosze' => $expectedGrosze,
            'notifiedGrosze' => $notifiedGrosze,
            'alert' => 'payments.amount_mismatch',
        ]);
    }

    /**
     * The `pending` -> `confirmed` transition for a Zapis whose trasa-A payment
     * just cleared. Must be called from inside applyGatewayNotification()'s own
     * transaction (it does not open one itself — PDO has no real nested
     * transactions) so the gateway-transaction row flip and this stay one atomic
     * unit. A Zapis already out of `pending` (already confirmed by an earlier
     * notification, or since cancelled/expired) is left alone — belt-and-braces
     * alongside the transaction-row idempotency check above, the same
     * "re-check under the lock" pattern the rest of this class uses.
     */
    private function markGatewayPaymentPaid(string $registrationId): void
    {
        $registration = $this->lockRegistration($registrationId);
        if ($registration === null || $registration['status'] !== 'pending') {
            return;
        }

        $this->updateById('registrations', $registrationId, ['status' => 'confirmed']);

        $settlement = $this->settlementItems($registrationId);
        $computed = $this->computedPaymentAmounts($settlement);
        $hasInsurance = false;
        foreach ($settlement as $item) {
            if ($item['basis'] === 'insurance') {
                $hasInsurance = true;
                break;
            }
        }
        $existingDetails = $this->paymentDetails($registrationId) ?? [];
        $now = date(DATE_ATOM);
        $this->upsertPaymentDetails($registrationId, [
            ...$existingDetails,
            'startFeePaid' => true,
            'startFeePaidAt' => $existingDetails['startFeePaidAt'] ?? $now,
            'startFeeAmount' => $computed['startFeeAmount'],
            'insurancePaid' => $hasInsurance,
            'insurancePaidAt' => $hasInsurance ? ($existingDetails['insurancePaidAt'] ?? $now) : null,
            'insuranceAmount' => $computed['insuranceAmount'],
        ]);
        // registrations.status moved pending -> confirmed above; both are counted
        // statuses (NOT_COUNTED_STATUSES excludes both), so the Dystans
        // registered_count needs no adjustment here — same reasoning as
        // updateStatus()'s wasCounted/isCounted branches, just already known true/true.
    }

    /**
     * E1.8: trasa B "oznacz jako opłacone" — the organizer (own Wydarzenie) or
     * admin manually confirms a bank transfer they saw land in their own
     * account. This performs *exactly* the same `pending` -> `confirmed`
     * transition applyGatewayNotification() performs for trasa A —
     * markGatewayPaymentPaid() is reused as-is, not duplicated, per the
     * ticket's "no parallel ad-hoc status path" requirement. The only
     * difference from the webhook is what triggers it: an authenticated
     * organizer/admin clicking a button (permission-checked in routes.php
     * via $canManageEvent), not a signed gateway callback.
     *
     * Guards against exactly the misuse the ticket calls out: a trasa-A
     * (gateway) registration must never be marked paid through this door —
     * it throws rather than silently doing nothing, so the route/http layer
     * can surface a clear error instead of a no-op 200.
     *
     * @return array<string, mixed>
     */
    public function markManualPaymentPaid(string $id): array
    {
        $eventId = $this->registrationEventId($id);
        if ($eventId === null) {
            throw new \RuntimeException('registration_not_found');
        }
        if ($this->paymentConfigType($eventId) !== 'organizer_bank') {
            throw new \RuntimeException('registration_not_manual_route');
        }

        $this->pdo->beginTransaction();
        try {
            $registration = $this->lockRegistration($id);
            if ($registration === null) {
                throw new \RuntimeException('registration_not_found');
            }
            if ($registration['status'] !== 'pending') {
                throw new \RuntimeException('registration_not_pending');
            }
            $this->markGatewayPaymentPaid($id);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // E1.10 mail #5 — see applyGatewayNotification()'s identical comment on
        // why this happens after the commit, not inside markGatewayPaymentPaid().
        $this->sendPaymentConfirmedMail($id);

        $registration = $this->find($id);
        if ($registration === null) {
            throw new \RuntimeException('registration_not_found');
        }

        return $registration;
    }

    /**
     * Shared by create() (a Zapis born already-paid), applyGatewayNotification()
     * (trasa A) and markManualPaymentPaid() (trasa B) — every path that lands a
     * Zapis on `confirmed` funnels through here for mail #5, so there is exactly
     * one place that knows how to turn a paid Zapis into that email. Reads its
     * own fresh row rather than accepting one from the caller so it never risks
     * sending stale eventName/distanceName/userEmail.
     */
    private function sendPaymentConfirmedMail(string $registrationId): void
    {
        if ($this->mailer === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT event_id AS eventId, event_name AS eventName, distance_name AS distanceName, user_email AS userEmail
             FROM registrations WHERE id = :id'
        );
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $this->mailer->sendPaymentConfirmed(
            $registrationId,
            (string) $row['eventId'],
            (string) $row['userEmail'],
            (string) $row['eventName'],
            (string) $row['distanceName'],
            rtrim((string) \ZmierzymyCzas\Support\Env::get('APP_URL', ''), '/') . '/my-starts'
        );
    }

    private function sendRefundApprovedMail(string $registrationId, float $amount, string $route): void
    {
        if ($this->mailer === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT event_id AS eventId, event_name AS eventName, user_email AS userEmail FROM registrations WHERE id = :id'
        );
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $this->mailer->sendRefundApproved(
            $registrationId,
            (string) $row['eventId'],
            (string) $row['userEmail'],
            (string) $row['eventName'],
            number_format($amount, 2, '.', ''),
            $route === 'gateway' ? 'zwrot na kartę/konto użyte do płatności' : 'przelew tradycyjny'
        );
    }

    /**
     * E3.4: "Push zmian krytycznych (rezygnacja, zwrot) webhookiem" (issue 12
     * §1). Two independent gates, both required, mirrored from the opposite
     * direction of routes.php's own BZ pull endpoint (`GET
     * /events/{eventId}/bz/participants`, E3.3):
     *  1. This RegistrationRepository must actually have a BzWebhookOutboxService
     *     at all — null whenever BZ_WEBHOOK_BASE_URL isn't configured for this
     *     deployment (see $bzWebhookOutbox's doc comment), same "unconfigured
     *     means skip, not fail" stance as every other outbound integration in
     *     this class.
     *  2. The specific Event this Zapis belongs to must have
     *     bz_integration_enabled = true AND a non-empty bz_organization_token
     *     (E3.2) — an Event that never turned BZ on gets zero pushes, exactly
     *     like it gets rejected on the pull side.
     *
     * Payload is a deliberate whitelist (issue 12 §5, "bez kwot" — the exact
     * same money-leakage discipline bzParticipantList() already applies to the
     * pull direction): just enough for BZ to resolve which participant record
     * changed (eventId, registrationId, and qrCode as a second identity
     * anchor — the same field BZ already receives from the pull and can index
     * on), what kind of critical change this is, and a timestamp for BZ's own
     * last-write-wins reconciliation (issue 12 §2). Never a settlement amount,
     * a price, or anything else hydrate()-shaped.
     *
     * Any failure enqueueing/sending is deliberately swallowed — same "never
     * let an external call block a state transition that already committed on
     * our side" stance as initiateGatewayRefund(). The outbox row is written
     * (and marked failed) before the HTTP attempt regardless, so nothing here
     * is silently lost — just not automatically retried (no cron/worker in
     * this repo, mirroring E1.9's own reconcile()).
     */
    private function pushBzCriticalChange(string $eventId, string $registrationId, string $changeType): void
    {
        if ($this->bzWebhookOutbox === null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT bz_integration_enabled AS bzIntegrationEnabled, bz_organization_token AS bzOrganizationToken FROM events WHERE id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $event = $stmt->fetch();
        if ($event === false || empty($event['bzIntegrationEnabled'])) {
            return;
        }
        $organizationToken = trim((string) ($event['bzOrganizationToken'] ?? ''));
        if ($organizationToken === '') {
            return;
        }

        $qrStmt = $this->pdo->prepare('SELECT qr_code AS qrCode FROM registrations WHERE id = :id');
        $qrStmt->execute(['id' => $registrationId]);
        $qrCode = $qrStmt->fetchColumn();

        $payload = [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'changeType' => $changeType,
            'occurredAt' => date(DATE_ATOM),
            'qrCode' => $qrCode !== false && $qrCode !== null ? (string) $qrCode : null,
        ];

        try {
            $this->bzWebhookOutbox->enqueue([
                'eventId' => $eventId,
                'registrationId' => $registrationId,
                'changeType' => $changeType,
                // Deterministic (never a timestamp/random suffix — same discipline
                // as CriticalMailer's mail idempotency keys): scoped to
                // (registration, change type), since each of the three change
                // types this class emits (cancellation, refund_approved,
                // refund_refunded) happens at most once per Zapis — its own
                // caller already guards against firing on a repeated/no-op
                // transition (see the three call sites above).
                'idempotencyKey' => sprintf('bz-push:%s:%s', $registrationId, $changeType),
                'organizationToken' => $organizationToken,
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            // Deliberately swallowed (see this method's doc comment), but PP.20
            // leaves a trace. Never log organizationToken/payload.
            $this->logger->warning('BZ critical change push failed', [
                'eventId' => $eventId,
                'registrationId' => $registrationId,
                'changeType' => $changeType,
                'exception' => $e,
            ]);
        }
    }

    /**
     * The refund cycle currently on file for this Zapis, if any — history from a
     * completed (`refunded`) cycle is kept, not deleted, so this stays non-null
     * after a refund finishes.
     *
     * @return array<string, mixed>|null
     */
    private function refund(string $registrationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, requested_at AS requestedAt, requested_by AS requestedBy, reason,
                    approved_at AS approvedAt, approved_by AS approvedBy, amount, route,
                    completed_at AS completedAt, completed_by AS completedBy
             FROM registration_refunds WHERE registration_id = :id'
        );
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['amount'] = $row['amount'] !== null ? (float) $row['amount'] : null;

        return $row;
    }

    /**
     * The full six-step sequential pricing algorithm (spec:
     * 07-model-cenowy-zapisu.md). Klient nigdy nie przysyła kwot (rozstrzygnięcie 14)
     * — every amount below is derived server-side from ids/selections the client
     * sends (pricingVariantId, discountCode, merchandise, insuranceVariantId); any
     * money-like field on $data (price, discountAmount, pricePaid, totalAmount,
     * insurancePrice, ...) is simply never read here.
     *
     * Steps 1–3 (Wariant → Próg → Kod rabatowy) freeze into "opłata startowa";
     * step 4 (Modyfikatory) is a real, always-called stage that today always
     * returns no items (ticket 10 / E1.13 owns its source); steps 5–6 (Gadżety,
     * NNW) are added on top. The running total is rounded to cents after every
     * step; nothing here lets it go below 0 except where a single step's own
     * result is clamped to ≥0 (step 3's result, and the final total).
     *
     * TD.8 (spec 07 pkt 10): $suppressStartFee, when true, skips steps 1–3
     * entirely (startFeeBaseItems() is never called, and the discount-code
     * block never runs) — $items starts empty, the running total starts at
     * 0.0, and no discount is looked up or applied. This is how a relay
     * team member JOINING an existing team on a `team_pricing_mode =
     * 'per_team'` Dystans gets a zero-priced opłata startowa (the whole fee
     * already landed on the captain's own Zapis) — see create()'s
     * $suppressStartFee derivation. Steps 4–6 (modifiers, gadżety, NNW)
     * always run regardless of this flag: the spec is explicit that
     * "Gadżety, NNW i modyfikatory zawsze zostają przy Zapisie zamawiającego".
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row locked Dystans row from lockDistanceForRegistration()
     * @param bool $suppressStartFee TD.8: true suppresses steps 1–3 (opłata startowa + discount code) for a relay team member joining under 'per_team' pricing
     * @return array{
     *     eventName: string,
     *     distanceName: string,
     *     settlementItems: array<int, array{name: string, basis: string, amount: float}>,
     *     merchandiseOrders: array<int, array<string, mixed>>,
     *     discountCode: ?string,
     *     discountId: ?string,
     *     usedBy: array<int, mixed>
     * }
     */
    private function pricingForRegistration(array $data, array $row, bool $suppressStartFee = false): array
    {
        if ($suppressStartFee) {
            // TD.8: this Zapis is a relay team member joining a 'per_team'
            // priced team — the whole opłata startowa already landed on the
            // captain's Zapis, so steps 1–3 (and any discount code) simply
            // never run for this one.
            $items = [];
            $runningTotal = 0.0;
            $discountCode = null;
            $discountId = null;
            $usedBy = [];
        } else {
            // ── Kroki 1+2: Wariant opłaty + jego własny Próg ────────────────
            $base = $this->startFeeBaseItems($row, isset($data['pricingVariantId']) && $data['pricingVariantId'] !== null && $data['pricingVariantId'] !== '' ? (string) $data['pricingVariantId'] : null);
            $items = $base['items'];
            $runningTotal = $base['resolvedPrice'];

            // ── Krok 3: Kod rabatowy (tylko na wynik kroku 2, wynik ≥ 0) ────
            $discountCode = trim((string) ($data['discountCode'] ?? ''));
            $discountId = null;
            $usedBy = [];
            if ($discountCode !== '') {
                $discount = $this->discountForRegistration(
                    (string) $data['eventId'],
                    (string) $data['distanceId'],
                    $discountCode
                );
                $discountValue = (float) $discount['discount_value'];
                $discountAmount = $discount['discount_type'] === 'percent'
                    ? $this->roundMoney($runningTotal * ($discountValue / 100))
                    : min($runningTotal, $this->roundMoney($discountValue));
                $discountAmount = $this->roundMoney($discountAmount);
                $runningTotal = $this->roundMoney(max(0, $runningTotal - $discountAmount));

                if ($discountAmount !== 0.0) {
                    $items[] = [
                        'name' => 'Kod rabatowy ' . strtoupper($discountCode),
                        'basis' => 'discount_code',
                        'amount' => -abs($discountAmount),
                    ];
                }
                $discountId = (string) $discount['id'];
                $usedBy = $discount['used_by'] !== null && $discount['used_by'] !== ''
                    ? (json_decode((string) $discount['used_by'], true) ?: [])
                    : [];
            }
        }
        // = OPŁATA STARTOWA (zamrożona: $items so far)

        // ── Krok 4: Modyfikatory — ticket 10 / E1.13 dziedziczy ten krok ────
        foreach ($this->modifierSettlementItems($data) as $modifierItem) {
            $items[] = $modifierItem;
        }

        // ── Krok 5: Gadżety (cena × ilość, cena zawsze z katalogu) ──────────
        $merchandise = $this->resolveMerchandiseLines((string) $data['eventId'], $data['merchandise'] ?? $data['merchandiseOrders'] ?? []);
        foreach ($merchandise['items'] as $merchandiseItem) {
            $items[] = $merchandiseItem;
        }

        // ── Krok 6: NNW (wariant z katalogu globalnego, wiek na dzień wydarzenia) ─
        $insuranceItem = $this->insuranceSettlementItem($data, (string) $row['eventDate']);
        if ($insuranceItem !== null) {
            $items[] = $insuranceItem;
        }
        // = DO ZAPŁATY (suma $items, ≥ 0 — egzekwowane w hydrate()/computedPaymentAmounts())

        return [
            'eventName' => (string) $row['eventName'],
            'distanceName' => (string) $row['distanceName'],
            'settlementItems' => $items,
            'merchandiseOrders' => $merchandise['orders'],
            'discountCode' => $discountCode !== null && $discountCode !== '' ? strtoupper($discountCode) : null,
            'discountId' => $discountId,
            'usedBy' => $usedBy,
        ];
    }

    /**
     * Steps 1+2: Wariant opłaty (or, when the Dystans has no Warianty configured,
     * the legacy Dystans price — backward compatibility for distances that never
     * got variants) plus that same Wariant's own Próg. Returns the base price as
     * one settlement line and, if a tier applies, the tier's own price as a second
     * line relative to the base (matching the spec's worked example, which shows
     * "Wariant STANDARDOWA 110,00" and "próg „Early bird" −60,00" as two lines).
     *
     * @param array<string, mixed> $distanceRow
     * @return array{items: array<int, array{name: string, basis: string, amount: float}>, resolvedPrice: float}
     */
    private function startFeeBaseItems(array $distanceRow, ?string $pricingVariantId): array
    {
        $variant = $pricingVariantId !== null
            ? $this->pricingVariant((string) $distanceRow['distanceId'], $pricingVariantId)
            : null;
        if ($pricingVariantId !== null && $variant === null) {
            throw new \RuntimeException('pricing_variant_invalid');
        }

        if ($variant !== null) {
            $name = (string) $variant['name'];
            $basePrice = $this->roundMoney((float) $variant['price']);
            $tiers = $this->decodeTiers($variant['priceTiers'] ?? null);
        } else {
            $name = 'Opłata startowa';
            $basePrice = $this->roundMoney((float) $distanceRow['distancePrice']);
            $tiers = $this->decodeTiers($distanceRow['priceTiers'] ?? null);
        }

        $items = [['name' => $name, 'basis' => 'distance_price', 'amount' => $basePrice]];
        $resolvedPrice = $basePrice;

        $tier = $this->firstActiveTier($tiers, (int) ($distanceRow['registeredCount'] ?? 0));
        if ($tier !== null) {
            $tierPrice = $this->priceForTier($basePrice, $tier);
            $delta = $this->roundMoney($tierPrice - $basePrice);
            if ($delta !== 0.0) {
                $label = trim((string) ($tier['name'] ?? ''));
                $items[] = [
                    'name' => 'Próg' . ($label !== '' ? ' „' . $label . '"' : ''),
                    'basis' => 'price_tier',
                    'amount' => $delta,
                ];
            }
            $resolvedPrice = $tierPrice;
        }

        return ['items' => $items, 'resolvedPrice' => $resolvedPrice];
    }

    /** @return array<string, mixed>|null */
    private function pricingVariant(string $distanceId, string $variantId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, price, price_tiers AS priceTiers FROM event_distance_pricing_variants WHERE id = :id AND distance_id = :distanceId');
        $stmt->execute(['id' => $variantId, 'distanceId' => $distanceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * A Próg (price tier) carries at most two independent, optional limits — an end
     * date and a registration-count limit — and expires on whichever is hit first
     * ("co nastąpi wcześniej"). There is no mode flag choosing between them: both can
     * apply to the same tier at once. Tiers are evaluated in the order they were
     * configured (their `price_tiers` array order); the first one that has not yet
     * expired by either limit wins. The count compared against `participantLimit` is
     * non-cancelled registrations (pending + confirmed) — the same population the
     * distance's own `participant_limit` counts against.
     *
     * @param array<int, array<string, mixed>> $tiers
     * @return array<string, mixed>|null
     */
    private function firstActiveTier(array $tiers, int $registeredCount): ?array
    {
        if ($tiers === []) {
            return null;
        }

        $today = date('Y-m-d');
        foreach ($tiers as $tier) {
            $endDate = (string) ($tier['endDate'] ?? '');
            $expiredByDate = $endDate !== '' && substr($endDate, 0, 10) < $today;

            $limit = (int) ($tier['participantLimit'] ?? 0);
            $expiredByCount = $limit > 0 && $registeredCount >= $limit;

            if (!$expiredByDate && !$expiredByCount) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Step 4 (Modyfikatory): pricing contributions from form-field-variant choices —
     * "posiłek wegetariański +5", "udział w losowaniu −5" (rozstrzygnięcie 8 w
     * spec). Not built yet: ticket 10 / E1.13 owns the source of these variants and
     * their prices. This method is the deliberate seam for that ticket — a real,
     * always-called stage in the pipeline, not a silently-skipped step — so E1.13
     * only has to change this one method's body, never pricingForRegistration()'s
     * shape or create()/hydrate()'s handling of settlement items.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{name: string, basis: string, amount: float}>
     */
    private function modifierSettlementItems(array $data): array
    {
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function decodeTiers(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<string, mixed> $tier */
    private function priceForTier(float $basePrice, array $tier): float
    {
        if (array_key_exists('price', $tier) && $tier['price'] !== null && $tier['price'] !== '') {
            return $this->roundMoney(max(0, (float) $tier['price']));
        }

        $value = (float) ($tier['discountValue'] ?? 0);
        if (($tier['discountType'] ?? 'percent') === 'fixed') {
            return $this->roundMoney(max(0, $basePrice - $value));
        }

        return $this->roundMoney(max(0, $basePrice * (1 - ($value / 100))));
    }

    /** @return array<string, mixed> */
    private function discountForRegistration(string $eventId, string $distanceId, string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_discount_codes WHERE event_id = :eventId AND UPPER(code) = UPPER(:code) LIMIT 1');
        $stmt->execute(['eventId' => $eventId, 'code' => $code]);
        $discount = $stmt->fetch();
        if (!$discount) {
            throw new \RuntimeException('discount_code_invalid');
        }
        if (!(bool) $discount['active']) {
            throw new \RuntimeException('discount_code_inactive');
        }

        $now = time();
        if (!empty($discount['valid_from']) && strtotime((string) $discount['valid_from']) > $now) {
            throw new \RuntimeException('discount_code_not_started');
        }
        if (!empty($discount['valid_to']) && strtotime((string) $discount['valid_to']) < $now) {
            throw new \RuntimeException('discount_code_expired');
        }
        if ($discount['max_uses'] !== null && (int) $discount['used_count'] >= (int) $discount['max_uses']) {
            throw new \RuntimeException('discount_code_used_up');
        }

        $distanceIds = $discount['distance_ids'] !== null && $discount['distance_ids'] !== ''
            ? (json_decode((string) $discount['distance_ids'], true) ?: [])
            : [];
        if ($distanceIds !== [] && !in_array($distanceId, $distanceIds, true)) {
            throw new \RuntimeException('discount_code_distance_mismatch');
        }

        return $discount;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{eventName: string, distanceName: string, settlementItems: array<int, array<string, mixed>>, merchandiseOrders: array<int, array<string, mixed>>, discountCode: ?string, discountId: ?string, usedBy: array<int, mixed>} $pricing
     */
    private function markDiscountUsed(string $discountId, string $registrationId, array $data, array $pricing): void
    {
        $usedBy = $pricing['usedBy'];
        $usedBy[] = [
            'userId' => $data['userId'],
            'userName' => $data['userName'],
            'usedAt' => date(DATE_ATOM),
            'registrationId' => $registrationId,
            'distanceName' => $pricing['distanceName'],
        ];

        $stmt = $this->pdo->prepare('UPDATE event_discount_codes SET used_count = used_count + 1, used_by = :usedBy WHERE id = :id');
        $stmt->execute([
            'id' => $discountId,
            'usedBy' => json_encode($usedBy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }

    /**
     * Basis values that make up "opłata startowa" (steps 1–3: Wariant, Próg, Kod
     * rabatowy) — everything else (modifier/merchandise/insurance) sits above it in
     * the six-step algorithm and is not part of `pricePaid`.
     */
    private const START_FEE_BASES = ['distance_price', 'price_tier', 'discount_code'];

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        // E1.8: deterministic, unique bank-transfer title for trasa B — pure
        // `ZC-{id}` derived from the Zapis id, exactly like a QR/ticket code
        // (E0.2's asTicket()) is derived rather than stored. Always present
        // (trasa A ignores it), so the client never has to special-case its
        // absence.
        $row['transferTitle'] = 'ZC-' . $row['id'];
        $row['merchandiseOrders'] = $this->merchandise($row['id']);
        $row['paymentDetails'] = $this->paymentDetails($row['id']);
        $row['refund'] = $this->refund($row['id']);

        $settlement = $this->settlementItems($row['id']);
        $row['settlementItems'] = $settlement;
        $row['pricePaid'] = null;
        $row['discountAmount'] = null;
        $row['insurancePaid'] = null;
        $hasStartFee = false;
        $hasDiscount = false;
        $hasInsurance = false;
        $startFee = 0.0;
        $discountTotal = 0.0;
        $insuranceTotal = 0.0;
        $total = 0.0;
        foreach ($settlement as $item) {
            $amount = (float) $item['amount'];
            $total += $amount;
            if (in_array($item['basis'], self::START_FEE_BASES, true)) {
                $hasStartFee = true;
                $startFee += $amount;
            }
            if ($item['basis'] === 'discount_code') {
                $hasDiscount = true;
                $discountTotal += $amount;
            } elseif ($item['basis'] === 'insurance') {
                $hasInsurance = true;
                $insuranceTotal += $amount;
            }
        }
        if ($hasStartFee) {
            $row['pricePaid'] = $this->roundMoney($startFee);
        }
        if ($hasDiscount) {
            $row['discountAmount'] = $this->roundMoney(abs($discountTotal));
        }
        if ($hasInsurance) {
            $row['insurancePaid'] = $this->roundMoney($insuranceTotal);
        }
        // DO ZAPŁATY: the full six-step total, floored at 0 (rozstrzygnięcie: spec
        // §"Algorytm"). Always present — unlike pricePaid/discountAmount/insurancePaid,
        // which stay null when their step never fired, "how much is owed in total" is
        // meaningful even for a registration with no discount/insurance/merchandise.
        $row['totalAmount'] = $this->roundMoney(max(0, $total));

        return $row;
    }

    /**
     * Rozliczenie: the positioned, frozen line items belonging to this Zapis
     * (name, basis, amount, freeze timestamp) — replaces the old flat
     * `price_paid` / `discount_amount` / `insurance_paid` columns (E0.3).
     *
     * @return array<int, array<string, mixed>>
     */
    private function settlementItems(string $registrationId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, basis, amount, frozen_at AS frozenAt FROM registration_settlement_items WHERE registration_id = :id ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['id' => $registrationId]);
        return array_map(
            static fn (array $item): array => [...$item, 'amount' => (float) $item['amount']],
            $stmt->fetchAll()
        );
    }

    /**
     * Writes the frozen Rozliczenie: one row per settlement item, in the order the
     * six-step algorithm produced them (sort_order = position in the list).
     *
     * @param array<int, array{name: string, basis: string, amount: float}> $items
     */
    private function createSettlementItems(string $registrationId, array $items, string $frozenAt): void
    {
        foreach (array_values($items) as $index => $item) {
            $this->insert('registration_settlement_items', [
                'id' => $this->id(),
                'registration_id' => $registrationId,
                'name' => $item['name'],
                'basis' => $item['basis'],
                'amount' => $this->roundMoney((float) $item['amount']),
                'frozen_at' => $frozenAt,
                'sort_order' => $index,
            ]);
        }
    }

    /** @return array<string, float> */
    private function computedPaymentAmounts(array $items): array
    {
        $startFee = 0.0;
        $insurance = 0.0;
        foreach ($items as $item) {
            if (in_array($item['basis'], self::START_FEE_BASES, true)) {
                $startFee += (float) $item['amount'];
            } elseif ($item['basis'] === 'insurance') {
                $insurance += (float) $item['amount'];
            }
        }

        return [
            'startFeeAmount' => $this->roundMoney(max(0, $startFee)),
            'insuranceAmount' => $this->roundMoney($insurance),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function merchandise(string $registrationId): array
    {
        $stmt = $this->pdo->prepare('SELECT merchandise_id AS merchandiseId, merchandise_name AS merchandiseName, size, color, color_hex AS colorHex, quantity, price FROM registration_merchandise_orders WHERE registration_id = :id');
        $stmt->execute(['id' => $registrationId]);
        return $stmt->fetchAll();
    }

    private function registrationEventId(string $registrationId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT event_id FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);
        $eventId = $stmt->fetchColumn();
        return $eventId !== false ? (string) $eventId : null;
    }

    /**
     * Step 5 (Gadżety): resolves client-sent merchandise lines ({merchandiseId,
     * size, color, quantity} — no price, per "klient nigdy nie przysyła kwot") into
     * both a settlement-item line (cena × ilość) and a `registration_merchandise_orders`
     * row, always pricing from the event's own merchandise catalog. A line whose
     * merchandiseId does not resolve against this event's catalog is rejected —
     * silently trusting an unknown id would let a client invent a free line.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array{items: array<int, array{name: string, basis: string, amount: float}>, orders: array<int, array<string, mixed>>}
     */
    private function resolveMerchandiseLines(string $eventId, array $lines): array
    {
        $items = [];
        $orders = [];
        foreach ($lines as $line) {
            $merchandiseId = (string) ($line['merchandiseId'] ?? '');
            if ($merchandiseId === '') {
                continue;
            }
            $catalog = $this->merchandiseCatalogItem($eventId, $merchandiseId);
            if ($catalog === null) {
                throw new \RuntimeException('merchandise_invalid');
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $unitPrice = $this->roundMoney((float) $catalog['price']);
            $lineTotal = $this->roundMoney($unitPrice * $quantity);
            $size = isset($line['size']) && $line['size'] !== '' ? (string) $line['size'] : null;
            $color = isset($line['color']) && $line['color'] !== '' ? (string) $line['color'] : null;

            $items[] = [
                'name' => (string) $catalog['name'] . ($size !== null ? ' (' . $size . ')' : ''),
                'basis' => 'merchandise',
                'amount' => $lineTotal,
            ];
            $orders[] = [
                'merchandiseId' => $merchandiseId,
                'merchandiseName' => $catalog['name'],
                'size' => $size,
                'color' => $color,
                'colorHex' => $this->colorHexFor($catalog, $color),
                'quantity' => $quantity,
                'price' => $unitPrice,
            ];
        }

        return ['items' => $items, 'orders' => $orders];
    }

    /** @return array<string, mixed>|null */
    private function merchandiseCatalogItem(string $eventId, string $merchandiseId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, price, colors FROM event_merchandise WHERE id = :id AND event_id = :eventId');
        $stmt->execute(['id' => $merchandiseId, 'eventId' => $eventId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $catalog */
    private function colorHexFor(array $catalog, ?string $color): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }
        $raw = $catalog['colors'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $entry) {
            if (is_array($entry) && (string) ($entry['name'] ?? '') === $color) {
                return isset($entry['hex']) ? (string) $entry['hex'] : null;
            }
        }
        return null;
    }

    /**
     * Step 6 (NNW): the client picks one catalog variant by id — the price formula
     * itself is never sent, only the choice. Validates that the variant is both a
     * real, active catalog row and one this event actually offers (rozstrzygnięcie
     * 9: organizator wybiera oferowane spośród admina katalogu). Age is computed
     * against the event's own date, not today or the registration timestamp
     * (rozstrzygnięcie 9, ostatnie zdanie).
     *
     * @param array<string, mixed> $data
     * @return array{name: string, basis: string, amount: float}|null
     */
    private function insuranceSettlementItem(array $data, string $eventDate): ?array
    {
        $variantId = trim((string) ($data['insuranceVariantId'] ?? ''));
        if ($variantId === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT iv.id, iv.name, iv.base_fee AS baseFee, iv.age_multiplier AS ageMultiplier, iv.max_age AS maxAge
             FROM event_insurance_variants eiv
             INNER JOIN insurance_settings iv ON iv.id = eiv.insurance_variant_id
             WHERE eiv.event_id = :eventId AND iv.id = :variantId AND iv.active = 1'
        );
        $stmt->execute(['eventId' => (string) $data['eventId'], 'variantId' => $variantId]);
        $variant = $stmt->fetch();
        if (!$variant) {
            throw new \RuntimeException('insurance_variant_invalid');
        }

        $birthDate = $this->resolveBirthDate($data);
        if ($birthDate === null) {
            throw new \RuntimeException('insurance_birth_date_missing');
        }

        $age = $this->ageAsOf($birthDate, $eventDate);
        $maxAge = (int) $variant['maxAge'];
        $cappedAge = $maxAge > 0 ? min($age, $maxAge) : $age;
        $amount = $this->roundMoney((float) $variant['baseFee'] + max(0, $cappedAge - 18) * (float) $variant['ageMultiplier']);

        return ['name' => 'NNW ' . (string) $variant['name'], 'basis' => 'insurance', 'amount' => $amount];
    }

    /**
     * Zawodnik's birth date, looked up via competitorId when this Zapis carries one
     * (the normal path — see routes.php's competitorId resolution) — falling back
     * to a directly-sent `birthDate` on $data for callers that don't have a
     * Competitor row (older integrations, tests). Only used for the NNW age
     * formula; never trusted as a money-affecting field.
     *
     * @param array<string, mixed> $data
     */
    private function resolveBirthDate(array $data): ?string
    {
        $competitorId = (string) ($data['competitorId'] ?? '');
        if ($competitorId !== '') {
            $stmt = $this->pdo->prepare('SELECT birth_date FROM competitors WHERE id = :id');
            $stmt->execute(['id' => $competitorId]);
            $birthDate = $stmt->fetchColumn();
            if ($birthDate !== false && $birthDate !== null && $birthDate !== '') {
                return (string) $birthDate;
            }
        }

        $fallback = (string) ($data['birthDate'] ?? '');
        return $fallback !== '' ? $fallback : null;
    }

    private function ageAsOf(string $birthDate, string $asOfDate): int
    {
        $birth = new \DateTimeImmutable(substr($birthDate, 0, 10));
        $asOf = new \DateTimeImmutable(substr($asOfDate, 0, 10) !== '' ? substr($asOfDate, 0, 10) : date('Y-m-d'));
        return $asOf->diff($birth)->y;
    }

    private function paymentDetails(string $registrationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT start_fee_paid AS startFeePaid, start_fee_paid_at AS startFeePaidAt, start_fee_amount AS startFeeAmount, insurance_paid AS insurancePaid, insurance_paid_at AS insurancePaidAt, insurance_amount AS insuranceAmount, merchandise_payments AS merchandisePayments FROM registration_payment_details WHERE registration_id = :id');
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['startFeePaid'] = (bool) $row['startFeePaid'];
        $row['insurancePaid'] = (bool) $row['insurancePaid'];
        $row['merchandisePayments'] = $row['merchandisePayments'] ? json_decode((string) $row['merchandisePayments'], true) : [];
        return $row;
    }

    /** Full replace, given already catalog-resolved order rows. @param array<int, array<string, mixed>> $resolvedOrders */
    private function replaceMerchandise(string $registrationId, array $resolvedOrders): void
    {
        $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $registrationId]);
        $this->insertMerchandiseOrders($registrationId, $resolvedOrders);
    }

    /** Insert-only — used on create(), where there is nothing to delete yet. @param array<int, array<string, mixed>> $resolvedOrders */
    private function insertMerchandiseOrders(string $registrationId, array $resolvedOrders): void
    {
        foreach ($resolvedOrders as $item) {
            $this->insert('registration_merchandise_orders', [
                'id' => $this->id(),
                'registration_id' => $registrationId,
                'merchandise_id' => $item['merchandiseId'],
                'merchandise_name' => $item['merchandiseName'],
                'size' => $item['size'] ?? null,
                'color' => $item['color'] ?? null,
                'color_hex' => $item['colorHex'] ?? null,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);
        }
    }

    /** @param array<string, mixed> $details */
    private function upsertPaymentDetails(string $registrationId, array $details): void
    {
        $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
        $this->insert('registration_payment_details', [
            'registration_id' => $registrationId,
            'start_fee_paid' => (int) ($details['startFeePaid'] ?? false),
            'start_fee_paid_at' => $details['startFeePaidAt'] ?? null,
            'start_fee_amount' => $details['startFeeAmount'] ?? 0,
            'insurance_paid' => (int) ($details['insurancePaid'] ?? false),
            'insurance_paid_at' => $details['insurancePaidAt'] ?? null,
            'insurance_amount' => $details['insuranceAmount'] ?? null,
            'merchandise_payments' => json_encode($details['merchandisePayments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
