<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/**
 * R-02 (spec 20 §2 + spec 07 pkt 12, zakres P2 - do potwierdzenia przez Zamawiającego): change of the
 * Dystans (and/or the Wariant opłaty) of an existing Zapis. Part of RegistrationRepository - it needs
 * the pricing, locking and refund helpers that class keeps private - split out only to keep that file
 * from growing further.
 *
 * Rules (the self-service window is decided by the caller with SelfServicePolicy):
 *  - the Zapis is moved to the target Dystans: capacity checked on it under lock, `registered_count`
 *    moved, Kategoria recomputed, start code kept, numer startowy cleared (assigned again later);
 *  - the opłata startowa (Wariant + Próg + Kod rabatowy) is priced again at the price list of today;
 *    every other settlement line (modifiers, merchandise, NNW) keeps its frozen amount; the discount
 *    code stays only if it is still valid for the target Dystans, else its use is released;
 *  - balance = new total - paid (paid = the old total for a `confirmed` Zapis, minus a balance that an
 *    earlier change left open; nothing for a `pending` one, which has no balance, it just owes the new
 *    total). Balance < 0: a refund request for the overpayment is opened in the same transaction;
 *    = 0: nothing; > 0: the owner is refused (`balance_due_requires_organizer`), the Event's manager
 *    may change and settles the extra by hand (`balanceDueMinor` in the response, no online payment).
 */
trait RegistrationDistanceChange
{
    /**
     * What the change would look like right now, WITHOUT changing anything (no lock, no write).
     * The same numbers changeDistance() commits. `blockedReason` is set instead of an exception when the
     * actor may not do it or something is in the way; capacity / unknown Dystans still throw like the change.
     *
     * @param array{id: string, role: string, isManager: bool} $actor
     * @return array<string, mixed>
     */
    public function quoteDistanceChange(string $id, string $distanceId, ?string $pricingVariantId, ?string $category, array $actor): array
    {
        return $this->distanceChangeSummary($this->planDistanceChange($id, $distanceId, $pricingVariantId, $category, $actor, false));
    }

    /**
     * @param array{id: string, role: string, isManager: bool} $actor
     * @return array{registration: array<string, mixed>, change: array<string, mixed>, changeId: string, refundRequested: bool}
     * @throws DistanceChangeRejected
     */
    public function changeDistance(string $id, string $distanceId, ?string $pricingVariantId, ?string $category, array $actor): array
    {
        $this->pdo->beginTransaction();
        try {
            if ($this->lockRegistration($id) === null) {
                throw new DistanceChangeRejected('not_found', 'Nie znaleziono zapisu.', [], 404);
            }
            $plan = $this->planDistanceChange($id, $distanceId, $pricingVariantId, $category, $actor, true);
            if ($plan['blocked'] !== null) {
                throw new DistanceChangeRejected($plan['blocked']['reason'], $plan['blocked']['message'], $plan['blocked']['details'], $plan['blocked']['status']);
            }
            /** @var array<string, mixed> $before */
            $before = $plan['registration'];
            $targetId = (string) $plan['targetDistanceId'];
            $moved = !$plan['sameDistance'];

            if ($moved) {
                // incrementRegisteredCount() is the atomic capacity guard (throws registration_capacity_exceeded).
                $this->incrementRegisteredCount($targetId);
                $this->decrementRegisteredCount((string) $before['distanceId']);
            }
            $this->updateById('registrations', $id, [
                'distance_id' => $targetId,
                'distance_name' => (string) $plan['targetDistanceName'],
                'category' => (string) $plan['category'],
                'bib_number' => null,
                'discount_code' => $plan['discountCode'],
                'updated_at' => date(DATE_ATOM),
            ]);

            $bases = self::START_FEE_BASES;
            $this->pdo->prepare(
                'DELETE FROM registration_settlement_items WHERE registration_id = ? AND basis IN (' . implode(',', array_fill(0, count($bases), '?')) . ')'
            )->execute([$id, ...$bases]);
            $this->pdo->prepare('UPDATE registration_settlement_items SET sort_order = sort_order + :n WHERE registration_id = :id')
                ->execute(['n' => count($plan['startItems']), 'id' => $id]);
            $this->createSettlementItems($id, $plan['startItems'], date(DATE_ATOM));

            $details = $this->paymentDetails($id);
            if ($details !== null) {
                $computed = $this->computedPaymentAmounts([...$plan['startItems'], ...$plan['keptItems']]);
                $this->upsertPaymentDetails($id, [...$details, 'startFeeAmount' => $computed['startFeeAmount']]);
            }
            if ($plan['discountDropped']) {
                $this->releaseDiscountUse((string) $before['eventId'], (string) $before['discountCode'], $id);
            }

            $refundRequested = false;
            if ($plan['refundDue'] > 0.0) {
                $this->upsertRefundRow($id, [
                    'status' => 'requested',
                    'requested_at' => date(DATE_ATOM),
                    'requested_by' => $actor['id'],
                    'reason' => self::DISTANCE_CHANGE_REFUND_REASON,
                    'approved_at' => null,
                    'approved_by' => null,
                    'amount' => $plan['refundDue'],
                    'route' => null,
                    'completed_at' => null,
                    'completed_by' => null,
                ]);
                $refundRequested = true;
            }

            $summary = $this->distanceChangeSummary($plan);
            $changeId = (new RegistrationChangeRepository($this->pdo))->record(
                $id,
                RegistrationChangeRepository::TYPE_DISTANCE_CHANGED,
                [
                    'distanceId' => (string) $before['distanceId'],
                    'distanceName' => (string) $before['distanceName'],
                    'category' => (string) ($before['category'] ?? ''),
                    'bibNumber' => $before['bibNumber'] ?? null,
                    'totalAmount' => $plan['oldTotal'],
                ],
                [
                    'distanceId' => $targetId,
                    'distanceName' => (string) $plan['targetDistanceName'],
                    'category' => (string) $plan['category'],
                    'totalAmount' => $plan['newTotal'],
                    'paidAmount' => $plan['paid'],
                    'balanceDueMinor' => $summary['balanceDueMinor'],
                    'refundDueMinor' => $summary['refundDueMinor'],
                    'refundRequested' => $refundRequested,
                    'discountDropped' => $plan['discountDropped'],
                ],
                ['id' => $actor['id'], 'role' => $actor['isManager'] ? 'manager' : 'owner']
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->mailer?->sendEventChanged(
            (string) $before['eventId'],
            $id,
            (string) $before['userEmail'],
            (string) $before['eventName'],
            'Zmieniono dystans Twojego zapisu: ' . (string) $before['distanceName'] . ' → ' . (string) $plan['targetDistanceName'] . '.',
            $refundRequested
                ? 'Złożyliśmy wniosek o zwrot nadpłaty - organizator rozpatrzy go zgodnie z zasadami wydarzenia.'
                : ($summary['balanceDueMinor'] > 0
                    ? 'Do dopłaty: ' . number_format($summary['balanceDueMinor'] / 100, 2, ',', ' ') . ' zł - organizator skontaktuje się w sprawie rozliczenia.'
                    : 'Zmiana nie wymagała rozliczenia.'),
            $changeId
        );
        // Biuro Zawodów: same webhook outbox as the cancellation / refund pushes. BZ answers a type it does
        // not know with 200 "ignored_unknown_type"; the participant list it pulls carries the new Dystans.
        $this->pushBzCriticalChange((string) $before['eventId'], $id, 'distance_change', $changeId);

        return [
            'registration' => (array) $this->find($id),
            'change' => $summary,
            'changeId' => $changeId,
            'refundRequested' => $refundRequested,
        ];
    }

    /**
     * @param array{id: string, role: string, isManager: bool} $actor
     * @return array<string, mixed>
     * @throws DistanceChangeRejected for a Zapis that cannot be changed at all
     */
    private function planDistanceChange(string $id, string $targetDistanceId, ?string $pricingVariantId, ?string $category, array $actor, bool $forUpdate): array
    {
        $registration = $this->find($id);
        if ($registration === null) {
            throw new DistanceChangeRejected('not_found', 'Nie znaleziono zapisu.', [], 404);
        }
        if (!in_array((string) $registration['status'], ['pending', 'confirmed'], true)) {
            throw new DistanceChangeRejected('registration_not_active', 'Ten zapis nie jest już aktywny.');
        }
        if ($registration['teamId'] !== null && (string) $registration['teamId'] !== '') {
            throw new DistanceChangeRejected('distance_change_team_registration', 'Zapisu w drużynie (sztafecie) nie można przenieść na inny dystans - zmianę składu załatwia kapitan lub organizator.');
        }
        $variantId = $pricingVariantId !== null && trim($pricingVariantId) !== '' ? trim($pricingVariantId) : null;
        $eventId = (string) $registration['eventId'];
        $currentId = (string) $registration['distanceId'];
        $same = $targetDistanceId === $currentId;
        if ($same && $variantId === null) {
            throw new DistanceChangeRejected('distance_change_no_change', 'Wybierz inny dystans lub wariant opłaty.', [], 422);
        }

        // Lock order = id order, so two concurrent changes in opposite directions cannot deadlock.
        $rows = [];
        $ids = array_values(array_unique([$currentId, $targetDistanceId]));
        sort($ids);
        foreach ($ids as $distanceId) {
            $rows[$distanceId] = $this->lockDistanceForRegistration($eventId, $distanceId, $forUpdate);
        }
        $targetRow = $rows[$targetDistanceId];
        if (!$same) {
            $this->enforceCapacity($targetRow);
        }
        // The Zapis counts in its own Dystans already; the Próg count must not include itself.
        $priceRow = $same ? [...$targetRow, 'registeredCount' => max(0, (int) $targetRow['registeredCount'] - 1)] : $targetRow;

        $discountCode = trim((string) ($registration['discountCode'] ?? ''));
        $data = [
            'eventId' => $eventId,
            'distanceId' => $targetDistanceId,
            'pricingVariantId' => $variantId,
            'discountCode' => $discountCode,
            'actorUserId' => $actor['id'],
            'changingRegistrationId' => $id,
        ];
        $discountDropped = false;
        try {
            $pricing = $this->pricingForRegistration($data, $priceRow);
        } catch (\RuntimeException $e) {
            if ($discountCode === '' || !str_starts_with($e->getMessage(), 'discount_code_')) {
                throw $e;
            }
            $discountDropped = true;
            $pricing = $this->pricingForRegistration([...$data, 'discountCode' => ''], $priceRow);
        }
        $startItems = array_values(array_filter(
            $pricing['settlementItems'],
            static fn (array $item): bool => in_array($item['basis'], self::START_FEE_BASES, true)
        ));
        $startItems = array_map(fn (array $item): array => [...$item, 'amount' => $this->roundMoney((float) $item['amount'])], $startItems);
        $existing = $this->settlementItems($id);
        $keptItems = array_values(array_filter(
            $existing,
            static fn (array $item): bool => !in_array($item['basis'], self::START_FEE_BASES, true)
        ));

        $newCategory = $this->resolveRegistrationCategory(
            ['competitorId' => (string) ($registration['competitorId'] ?? ''), 'category' => $category ?? (string) ($registration['category'] ?? '')],
            $targetRow
        );

        $oldTotal = $this->amountDueFromItems($existing);
        $newTotal = $this->amountDueFromItems([...$startItems, ...$keptItems]);
        $confirmed = (string) $registration['status'] === 'confirmed';
        $paid = $confirmed ? max(0.0, $this->roundMoney($oldTotal - $this->outstandingBalance($id))) : 0.0;
        $balance = $confirmed ? $this->roundMoney($newTotal - $paid) : 0.0;

        $blocked = null;
        $blockedBy = static fn (string $reason, string $message, array $details = []): array => ['reason' => $reason, 'message' => $message, 'details' => $details, 'status' => 409];
        if (!$actor['isManager'] && $balance > 0.0) {
            $blocked = $blockedBy(
                'balance_due_requires_organizer',
                'Ten dystans jest droższy - dopłatę rozlicza organizator. Skontaktuj się z organizatorem wydarzenia.',
                ['balanceDueMinor' => (int) round($balance * 100)]
            );
        } elseif (!$confirmed && $this->hasOpenGatewayTransaction($id)) {
            $blocked = $blockedBy('payment_in_progress', 'Trwa płatność za ten zapis - dokończ ją albo poczekaj na jej wygaśnięcie, zanim zmienisz dystans.');
        } elseif ($balance < 0.0) {
            $refund = $this->refund($id);
            if ($refund !== null && $refund['status'] !== 'refunded') {
                $blocked = $blockedBy('refund_in_progress', 'Dla tego zapisu trwa już procedura zwrotu - dokończ ją przed zmianą dystansu.');
            }
        }

        return [
            'registration' => $registration,
            'targetDistanceId' => $targetDistanceId,
            'targetDistanceName' => (string) $targetRow['distanceName'],
            'currentDistanceName' => (string) $registration['distanceName'],
            'sameDistance' => $same,
            'category' => $newCategory,
            'startItems' => $startItems,
            'keptItems' => $keptItems,
            'discountCode' => $pricing['discountCode'],
            'discountDropped' => $discountDropped,
            'oldTotal' => $oldTotal,
            'newTotal' => $newTotal,
            'paid' => $paid,
            'confirmed' => $confirmed,
            'balance' => $balance,
            'refundDue' => $balance < 0.0 ? $this->roundMoney(-$balance) : 0.0,
            'blocked' => $blocked,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function distanceChangeSummary(array $plan): array
    {
        $registration = (array) $plan['registration'];
        $balance = (float) $plan['balance'];
        $items = array_map(
            static fn (array $item): array => ['name' => (string) $item['name'], 'basis' => (string) $item['basis'], 'amount' => (float) $item['amount']],
            [...$plan['startItems'], ...$plan['keptItems']]
        );

        return [
            'registrationId' => (string) $registration['id'],
            'from' => ['distanceId' => (string) $registration['distanceId'], 'distanceName' => (string) $plan['currentDistanceName']],
            'to' => ['distanceId' => (string) $plan['targetDistanceId'], 'distanceName' => (string) $plan['targetDistanceName']],
            'category' => (string) $plan['category'],
            'currentTotal' => $plan['oldTotal'],
            'newTotal' => $plan['newTotal'],
            'paidAmount' => $plan['paid'],
            'balance' => $balance,
            'balanceDueMinor' => $balance > 0.0 ? (int) round($balance * 100) : 0,
            'refundDueMinor' => $balance < 0.0 ? (int) round(-$balance * 100) : 0,
            'settlementItems' => $items,
            'discountDropped' => (bool) $plan['discountDropped'],
            'bibCleared' => ($registration['bibNumber'] ?? null) !== null && (string) $registration['bibNumber'] !== '',
            'blockedReason' => $plan['blocked']['reason'] ?? null,
            'blockedMessage' => $plan['blocked']['message'] ?? null,
        ];
    }

    /**
     * What an earlier change of Dystans left open for a `confirmed` Zapis: the last history row's
     * `balanceDueMinor` (PLN). Settling it by hand has no marker yet, so it is only ever deducted from
     * what counts as paid - never added - which can only make a later refund smaller, never larger.
     */
    private function outstandingBalance(string $registrationId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT to_json FROM registration_changes WHERE registration_id = :id AND type IN ('distance_changed', 'transferred') ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['id' => $registrationId]);
        $json = $stmt->fetchColumn();
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded) ? max(0.0, ((int) ($decoded['balanceDueMinor'] ?? 0)) / 100) : 0.0;
    }

    private function hasOpenGatewayTransaction(string $registrationId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM registration_gateway_transactions WHERE registration_id = :id AND status = 'created' LIMIT 1");
        $stmt->execute(['id' => $registrationId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $discount an `event_discount_codes` row */
    private function discountUsedBy(array $discount, string $registrationId): bool
    {
        $usedBy = $discount['used_by'] !== null && $discount['used_by'] !== '' ? json_decode((string) $discount['used_by'], true) : [];
        foreach (is_array($usedBy) ? $usedBy : [] as $use) {
            if (is_array($use) && (string) ($use['registrationId'] ?? '') === $registrationId) {
                return true;
            }
        }

        return false;
    }

    /** Gives back the use a Zapis held on a discount code that does not apply to its new Dystans. */
    private function releaseDiscountUse(string $eventId, string $code, string $registrationId): void
    {
        $stmt = $this->pdo->prepare('SELECT id, used_by FROM event_discount_codes WHERE event_id = :eventId AND UPPER(code) = UPPER(:code) LIMIT 1 FOR UPDATE');
        $stmt->execute(['eventId' => $eventId, 'code' => $code]);
        $row = $stmt->fetch();
        if (!$row || !$this->discountUsedBy($row, $registrationId)) {
            return;
        }
        $usedBy = array_values(array_filter(
            (array) json_decode((string) $row['used_by'], true),
            static fn (mixed $use): bool => !is_array($use) || (string) ($use['registrationId'] ?? '') !== $registrationId
        ));
        $this->pdo->prepare('UPDATE event_discount_codes SET used_count = GREATEST(0, used_count - 1), used_by = :usedBy WHERE id = :id')->execute([
            'id' => (string) $row['id'],
            'usedBy' => json_encode($usedBy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
