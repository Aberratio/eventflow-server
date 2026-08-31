<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use ZmierzymyCzas\Domain\Repository;

final class RegistrationRepository extends Repository
{
    private const COLUMNS = <<<'SQL'
id, event_id AS eventId, event_name AS eventName, user_id AS userId, competitor_id AS competitorId,
user_name AS userName,
user_email AS userEmail, distance_id AS distanceId, distance_name AS distanceName, category,
registered_at AS registeredAt, status, bib_number AS bibNumber, team_name AS teamName, team_id AS teamId,
discount_code AS discountCode, removed_at AS removedAt, checked_in_at AS checkedInAt,
registered_by_id AS registeredById
SQL;

    private const COLUMNS_R = <<<'SQL'
r.id, r.event_id AS eventId, r.event_name AS eventName, r.user_id AS userId, r.competitor_id AS competitorId,
r.user_name AS userName,
r.user_email AS userEmail, r.distance_id AS distanceId, r.distance_name AS distanceName, r.category,
r.registered_at AS registeredAt, r.status, r.bib_number AS bibNumber, r.team_name AS teamName, r.team_id AS teamId,
r.discount_code AS discountCode, r.removed_at AS removedAt, r.checked_in_at AS checkedInAt,
r.registered_by_id AS registeredById
SQL;

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = []): array
    {
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
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM registrations WHERE event_id = :eventId ORDER BY registered_at DESC');
        $stmt->execute(['eventId' => $eventId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function publicStartList(string $eventId, bool $showAll): array
    {
        if ($showAll) {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLUMNS . " FROM registrations WHERE event_id = :eventId AND status NOT IN ('removed', 'cancelled') ORDER BY registered_at DESC"
            );
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLUMNS_R . " FROM registrations r LEFT JOIN registration_payment_details pd ON pd.registration_id = r.id WHERE r.event_id = :eventId AND r.status NOT IN ('removed', 'cancelled') AND (r.status = 'confirmed' OR pd.start_fee_paid = 1) ORDER BY r.registered_at DESC"
            );
        }
        $stmt->execute(['eventId' => $eventId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function byUser(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM registrations WHERE user_id = :userId ORDER BY registered_at DESC');
        $stmt->execute(['userId' => $userId]);
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
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
        $status = in_array($registration['status'], ['cancelled', 'removed'], true)
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
            'issuedAt' => $registration['registeredAt'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->pdo->beginTransaction();
        try {
            $pricing = $this->pricingForRegistration($data);
            $paymentDetails = $data['paymentDetails'] ?? null;
            $startFeePaid = is_array($paymentDetails) && (bool) ($paymentDetails['startFeePaid'] ?? false);
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
                'status' => $startFeePaid ? 'confirmed' : 'pending',
                'team_name' => $data['teamName'] ?? null,
                'team_id' => $data['teamId'] ?? null,
                'registered_by_id' => $data['registeredById'] ?? null,
                'discount_code' => $pricing['discountCode'],
            ]);
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
            }
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
        $mapped = [];
        foreach ([
            'status' => 'status',
            'bibNumber' => 'bib_number',
            'teamName' => 'team_name',
            'teamId' => 'team_id',
            'discountCode' => 'discount_code',
            'removedAt' => 'removed_at',
            'checkedInAt' => 'checked_in_at',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $mapped[$column] = $data[$input];
            }
        }
        $this->updateById('registrations', $id, $mapped);
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

        return $this->find($id);
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
     * @param array<string, mixed> $data
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
    private function pricingForRegistration(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.name AS eventName, e.date AS eventDate, d.id AS distanceId, d.name AS distanceName,
                    d.price AS distancePrice, d.registered_count AS registeredCount, d.price_tiers AS priceTiers
             FROM events e INNER JOIN event_distances d ON d.event_id = e.id
             WHERE e.id = :eventId AND d.id = :distanceId'
        );
        $stmt->execute([
            'eventId' => $data['eventId'],
            'distanceId' => $data['distanceId'],
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('registration_distance_invalid');
        }

        // ── Kroki 1+2: Wariant opłaty + jego własny Próg ────────────────────
        $base = $this->startFeeBaseItems($row, isset($data['pricingVariantId']) && $data['pricingVariantId'] !== null && $data['pricingVariantId'] !== '' ? (string) $data['pricingVariantId'] : null);
        $items = $base['items'];
        $runningTotal = $base['resolvedPrice'];

        // ── Krok 3: Kod rabatowy (tylko na wynik kroku 2, wynik ≥ 0) ────────
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
            'discountCode' => $discountCode !== '' ? strtoupper($discountCode) : null,
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
        $row['merchandiseOrders'] = $this->merchandise($row['id']);
        $row['paymentDetails'] = $this->paymentDetails($row['id']);

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
