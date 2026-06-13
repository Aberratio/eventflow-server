<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use ZmierzymyCzas\Domain\Repository;

final class RegistrationRepository extends Repository
{
    private const COLUMNS = <<<'SQL'
id, event_id AS eventId, event_name AS eventName, user_id AS userId, user_name AS userName,
user_email AS userEmail, distance_id AS distanceId, distance_name AS distanceName, category,
registered_at AS registeredAt, status, bib_number AS bibNumber, team_name AS teamName, team_id AS teamId,
price_paid AS pricePaid, discount_code AS discountCode, discount_amount AS discountAmount,
insurance_paid AS insurancePaid, removed_at AS removedAt
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

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->pdo->beginTransaction();
        try {
            $pricing = $this->pricingForRegistration($data);
            $this->insert('registrations', [
                'id' => $id,
                'event_id' => $data['eventId'],
                'event_name' => $pricing['eventName'],
                'user_id' => $data['userId'],
                'user_name' => $data['userName'],
                'user_email' => $data['userEmail'],
                'distance_id' => $data['distanceId'],
                'distance_name' => $pricing['distanceName'],
                'category' => $data['category'],
                'registered_at' => date(DATE_ATOM),
                'status' => 'pending',
                'team_name' => $data['teamName'] ?? null,
                'team_id' => $data['teamId'] ?? null,
                'discount_code' => $pricing['discountCode'],
                'discount_amount' => $pricing['discountAmount'],
                'price_paid' => $pricing['pricePaid'],
            ]);
            if ($pricing['discountId'] !== null) {
                $this->markDiscountUsed($pricing['discountId'], $id, $data, $pricing);
            }
            $this->replaceMerchandise($id, $data['merchandiseOrders'] ?? []);
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
            'pricePaid' => 'price_paid',
            'discountCode' => 'discount_code',
            'discountAmount' => 'discount_amount',
            'insurancePaid' => 'insurance_paid',
            'removedAt' => 'removed_at',
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

    /** @param array<string, mixed> $paymentDetails */
    public function updatePaymentDetails(string $id, array $paymentDetails): ?array
    {
        $this->upsertPaymentDetails($id, $paymentDetails);
        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{eventName: string, distanceName: string, pricePaid: float, discountAmount: ?float, discountCode: ?string, discountId: ?string, usedBy: array<int, mixed>}
     */
    private function pricingForRegistration(array $data): array
    {
        $stmt = $this->pdo->prepare('SELECT e.name AS eventName, d.name AS distanceName, d.price AS distancePrice FROM events e INNER JOIN event_distances d ON d.event_id = e.id WHERE e.id = :eventId AND d.id = :distanceId');
        $stmt->execute([
            'eventId' => $data['eventId'],
            'distanceId' => $data['distanceId'],
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('registration_distance_invalid');
        }

        $distancePrice = $this->roundMoney((float) $row['distancePrice']);
        $discountCode = trim((string) ($data['discountCode'] ?? ''));
        if ($discountCode === '') {
            return [
                'eventName' => (string) $row['eventName'],
                'distanceName' => (string) $row['distanceName'],
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
            'pricePaid' => $this->roundMoney(max(0, $distancePrice - $discountAmount)),
            'discountAmount' => $discountAmount,
            'discountCode' => strtoupper($discountCode),
            'discountId' => (string) $discount['id'],
            'usedBy' => $discount['used_by'] !== null && $discount['used_by'] !== ''
                ? (json_decode((string) $discount['used_by'], true) ?: [])
                : [],
        ];
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
        return $row;
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
