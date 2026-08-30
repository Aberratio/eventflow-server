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
            $this->createSettlementItems($id, $pricing, date(DATE_ATOM));
            if ($pricing['discountId'] !== null) {
                $this->markDiscountUsed($pricing['discountId'], $id, $data, $pricing);
            }
            $this->replaceMerchandise($id, $data['merchandiseOrders'] ?? []);
            if (is_array($paymentDetails)) {
                $this->upsertPaymentDetails($id, $paymentDetails);
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
        if (array_key_exists('merchandiseOrders', $data)) {
            $this->replaceMerchandise($id, $data['merchandiseOrders'] ?? []);
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
     * @param array<string, mixed> $data
     * @return array{eventName: string, distanceName: string, distancePrice: float, pricePaid: float, discountAmount: ?float, discountCode: ?string, discountId: ?string, usedBy: array<int, mixed>}
     */
    private function pricingForRegistration(array $data): array
    {
        $stmt = $this->pdo->prepare('SELECT e.name AS eventName, d.id AS distanceId, d.name AS distanceName, d.price AS distancePrice, d.registered_count AS registeredCount, d.price_tiers AS priceTiers FROM events e INNER JOIN event_distances d ON d.event_id = e.id WHERE e.id = :eventId AND d.id = :distanceId');
        $stmt->execute([
            'eventId' => $data['eventId'],
            'distanceId' => $data['distanceId'],
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('registration_distance_invalid');
        }

        $distancePrice = $this->currentDistancePrice($row);
        $discountCode = trim((string) ($data['discountCode'] ?? ''));
        if ($discountCode === '') {
            return [
                'eventName' => (string) $row['eventName'],
                'distanceName' => (string) $row['distanceName'],
                'distancePrice' => $distancePrice,
                'pricePaid' => $distancePrice,
                'discountAmount' => null,
                'discountCode' => null,
                'discountId' => null,
                'usedBy' => [],
            ];
        }

        $discount = $this->discountForRegistration(
            (string) $data['eventId'],
            (string) $data['distanceId'],
            $discountCode
        );
        $discountValue = (float) $discount['discount_value'];
        $discountAmount = $discount['discount_type'] === 'percent'
            ? $this->roundMoney($distancePrice * ($discountValue / 100))
            : min($distancePrice, $this->roundMoney($discountValue));
        $discountAmount = $this->roundMoney($discountAmount);

        return [
            'eventName' => (string) $row['eventName'],
            'distanceName' => (string) $row['distanceName'],
            'distancePrice' => $distancePrice,
            'pricePaid' => $this->roundMoney(max(0, $distancePrice - $discountAmount)),
            'discountAmount' => $discountAmount,
            'discountCode' => strtoupper($discountCode),
            'discountId' => (string) $discount['id'],
            'usedBy' => $discount['used_by'] !== null && $discount['used_by'] !== ''
                ? (json_decode((string) $discount['used_by'], true) ?: [])
                : [],
        ];
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
     * @param array<string, mixed> $distance
     */
    private function currentDistancePrice(array $distance): float
    {
        $basePrice = $this->roundMoney((float) $distance['distancePrice']);
        $tiers = $this->decodeTiers($distance['priceTiers'] ?? null);
        if ($tiers === []) {
            return $basePrice;
        }

        $today = date('Y-m-d');
        $registeredCount = (int) ($distance['registeredCount'] ?? 0);

        foreach ($tiers as $tier) {
            $endDate = (string) ($tier['endDate'] ?? '');
            $expiredByDate = $endDate !== '' && substr($endDate, 0, 10) < $today;

            $limit = (int) ($tier['participantLimit'] ?? 0);
            $expiredByCount = $limit > 0 && $registeredCount >= $limit;

            if (!$expiredByDate && !$expiredByCount) {
                return $this->priceForTier($basePrice, $tier);
            }
        }

        return $basePrice;
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
     * @param array{eventName: string, distanceName: string, pricePaid: float, discountAmount: ?float, discountCode: ?string, discountId: ?string, usedBy: array<int, mixed>} $pricing
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
        $startFee = 0.0;
        foreach ($settlement as $item) {
            $amount = (float) $item['amount'];
            if ($item['basis'] === 'distance_price') {
                $hasStartFee = true;
                $startFee += $amount;
            } elseif ($item['basis'] === 'discount_code') {
                $hasStartFee = true;
                $startFee += $amount;
                $row['discountAmount'] = abs($amount);
            } elseif ($item['basis'] === 'insurance') {
                $row['insurancePaid'] = $amount;
            }
        }
        if ($hasStartFee) {
            $row['pricePaid'] = $this->roundMoney($startFee);
        }

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
     * @param array{distancePrice: float, discountAmount: ?float, discountCode: ?string} $pricing
     */
    private function createSettlementItems(string $registrationId, array $pricing, string $frozenAt): void
    {
        $this->insert('registration_settlement_items', [
            'id' => $this->id(),
            'registration_id' => $registrationId,
            'name' => 'Opłata startowa',
            'basis' => 'distance_price',
            'amount' => $pricing['distancePrice'],
            'frozen_at' => $frozenAt,
            'sort_order' => 0,
        ]);

        if ($pricing['discountAmount'] !== null && (float) $pricing['discountAmount'] !== 0.0) {
            $label = 'Kod rabatowy' . ($pricing['discountCode'] ? ' ' . $pricing['discountCode'] : '');
            $this->insert('registration_settlement_items', [
                'id' => $this->id(),
                'registration_id' => $registrationId,
                'name' => $label,
                'basis' => 'discount_code',
                'amount' => -abs((float) $pricing['discountAmount']),
                'frozen_at' => $frozenAt,
                'sort_order' => 1,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function merchandise(string $registrationId): array
    {
        $stmt = $this->pdo->prepare('SELECT merchandise_id AS merchandiseId, merchandise_name AS merchandiseName, size, color, color_hex AS colorHex, quantity, price FROM registration_merchandise_orders WHERE registration_id = :id');
        $stmt->execute(['id' => $registrationId]);
        return $stmt->fetchAll();
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

    /** @param array<int, array<string, mixed>> $items */
    private function replaceMerchandise(string $registrationId, array $items): void
    {
        $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $registrationId]);
        foreach ($items as $item) {
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
