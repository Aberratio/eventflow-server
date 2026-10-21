<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use ZmierzymyCzas\Domain\Errors\Conflict;
use ZmierzymyCzas\Domain\Errors\InvalidInput;
use ZmierzymyCzas\Domain\Errors\NotFound;
use ZmierzymyCzas\Support\InputValidationException;

/**
 * The Event's
 * manager moves a Zapis to another Zawodnik. Part of RegistrationRepository - it shares the person-swap core
 * with the relay team swap (swapRegistrationPerson()) and needs the settlement helpers that class keeps
 * private.
 *
 * The Zapis itself stays (id, Dystans, status, bib number, frozen Rozliczenie); the person, the contact data
 * and the Kategoria change, the start code rotates and the custom form answers are dropped (all in the shared
 * core). The NNW insurance is personal: its line is priced again for the new person (age at the Event date).
 * Money is never moved: a difference of the Rozliczenie is only reported (`balanceDueMinor` for a paid Zapis
 * that now owes more, `overpaymentMinor` for one that now owes less) and written to the history - no payment,
 * no refund, no settlement between the previous and the new person.
 */
trait RegistrationTransfer
{
    /**
     * @param array<string, mixed> $input `competitorId` (existing Zawodnik) or `newPerson`
     * @param array{id: string, email: string, role?: string} $actor the Event's manager
     * @return array{registration: array<string, mixed>, from: array<string, mixed>, to: array<string, mixed>, balanceDueMinor: int, overpaymentMinor: int, changeId: string}
     * @throws \RuntimeException with the machine code of the refusal as message
     */
    public function transferRegistration(string $id, array $input, array $actor): array
    {
        $this->pdo->beginTransaction();
        try {
            $locked = $this->lockRegistration($id);
            if ($locked === null) {
                throw new NotFound('registration_not_found');
            }
            $registration = (array) $this->find($id);
            if (!in_array((string) $locked['status'], ['pending', 'confirmed'], true)) {
                throw new Conflict('transfer_registration_inactive');
            }
            if (($registration['teamId'] ?? null) !== null && (string) $registration['teamId'] !== '') {
                throw new Conflict('transfer_team_registration');
            }
            $info = $this->pdo->prepare(
                'SELECT d.age_categories AS ageCategories, d.categories AS categories, d.special_categories AS specialCategories
                 FROM event_distances d WHERE d.event_id = :eventId AND d.id = :distanceId'
            );
            $info->execute(['eventId' => $locked['eventId'], 'distanceId' => $locked['distanceId']]);
            $distanceRow = $info->fetch();
            if (!$distanceRow) {
                throw new InvalidInput('registration_distance_invalid');
            }

            $oldItems = $this->settlementItems($id);
            $oldTotal = $this->amountDueFromItems($oldItems);
            $confirmed = (string) $locked['status'] === 'confirmed';
            $paid = $confirmed ? max(0.0, $this->roundMoney($oldTotal - $this->outstandingBalance($id))) : 0.0;
            $fromEmail = (string) ($registration['userEmail'] ?? '');
            $fromCode = (string) ($registration['qrCode'] ?? '');

            $swap = $this->swapRegistrationPerson($id, $locked, $distanceRow, $input, $actor, true);
            $competitor = $swap['competitor'];

            // Personal NNW line: priced again for the new person (age at the Event date, their own birth date).
            $insuranceChanged = false;
            foreach ($oldItems as $item) {
                if ($item['basis'] !== 'insurance') {
                    continue;
                }
                $fresh = $this->repriceInsuranceItem((string) $locked['eventId'], (string) $item['name'], (string) $competitor['id'], $input);
                if ($fresh !== null && $this->roundMoney((float) $fresh['amount']) !== $this->roundMoney((float) $item['amount'])) {
                    $this->pdo->prepare('UPDATE registration_settlement_items SET amount = :amount WHERE id = :id')
                        ->execute(['amount' => $this->roundMoney((float) $fresh['amount']), 'id' => (string) $item['id']]);
                    $insuranceChanged = true;
                }
            }
            $newItems = $insuranceChanged ? $this->settlementItems($id) : $oldItems;
            if ($insuranceChanged) {
                $details = $this->paymentDetails($id);
                if ($details !== null) {
                    $computed = $this->computedPaymentAmounts($newItems);
                    $this->upsertPaymentDetails($id, [...$details, 'insuranceAmount' => $computed['insuranceAmount']]);
                }
            }
            $newTotal = $this->amountDueFromItems($newItems);
            $balance = $confirmed ? $this->roundMoney($newTotal - $paid) : 0.0;
            $balanceDueMinor = $balance > 0.0 ? (int) round($balance * 100) : 0;
            $overpaymentMinor = $balance < 0.0 ? (int) round(-$balance * 100) : 0;

            $before = $swap['before'];
            $from = ['competitorId' => (string) $before['competitor_id'], 'name' => (string) $before['user_name']];
            $to = ['competitorId' => (string) $competitor['id'], 'name' => $swap['fullName']];
            $changeId = (new RegistrationChangeRepository($this->pdo))->record(
                $id,
                RegistrationChangeRepository::TYPE_TRANSFERRED,
                [...$from, 'category' => (string) ($before['category'] ?? ''), 'totalAmount' => $oldTotal],
                [
                    ...$to,
                    'category' => (string) ((array) $this->find($id))['category'],
                    'totalAmount' => $newTotal,
                    'paidAmount' => $paid,
                    'balanceDueMinor' => $balanceDueMinor,
                    'overpaymentMinor' => $overpaymentMinor,
                    'insuranceRepriced' => $insuranceChanged,
                    'startCodeRotated' => true,
                ],
                ['id' => $actor['id'], 'role' => 'manager']
            );
            $this->pdo->commit();
        } catch (InputValidationException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new \RuntimeException($e->errorCode === 'insurance_birth_date_missing' ? 'transfer_insurance_birth_date_missing' : 'transfer_insurance_invalid', 0, $e);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $updated = (array) $this->find($id);
        $eventId = (string) $registration['eventId'];
        $eventName = (string) $registration['eventName'];
        $summary = sprintf('Zapis na dystans %s został przeniesiony na inną osobę', (string) $registration['distanceName']);
        // Previous person: the Zapis is no longer theirs (their start code is void). New person: the confirmation
        // with the new start code. Both through the outbox, one key per change and side.
        if ($fromEmail !== '') {
            $this->mailer?->sendEventChanged(
                $eventId,
                $id,
                $fromEmail,
                $eventName,
                $summary . '. Twój dotychczasowy kod startowy' . ($fromCode !== '' ? ' (' . $fromCode . ')' : '') . ' przestaje obowiązywać.',
                'Zapis nie podlega zwrotowi w ramach tej zmiany - ewentualne rozliczenie z organizatorem odbywa się osobno.',
                'transfer-from-' . $changeId
            );
        }
        $toEmail = (string) ($updated['userEmail'] ?? '');
        if ($toEmail !== '') {
            $this->mailer?->sendEventChanged(
                $eventId,
                $id,
                $toEmail,
                $eventName,
                'Zapis na dystans ' . (string) $registration['distanceName'] . ' został przeniesiony na Ciebie. Twój nowy kod startowy: ' . (string) ($updated['qrCode'] ?? '') . '.',
                $balanceDueMinor > 0
                    ? 'Do dopłaty: ' . number_format($balanceDueMinor / 100, 2, ',', ' ') . ' zł - organizator skontaktuje się w sprawie rozliczenia.'
                    : 'Zapis nie wymaga dodatkowego rozliczenia.',
                'transfer-to-' . $changeId
            );
        }
        // Biuro Zawodów: the person and the start code behind the Zapis changed (unknown types are ignored there).
        $this->pushBzCriticalChange($eventId, $id, 'transfer', $changeId);

        return [
            'registration' => $updated,
            'from' => $from,
            'to' => $to,
            'balanceDueMinor' => $balanceDueMinor,
            'overpaymentMinor' => $overpaymentMinor,
            'changeId' => $changeId,
        ];
    }

    /**
     * The Zapis's NNW line priced for another person, or null when its variant can no longer be found
     * (removed / deactivated / not offered any more) - then the frozen amount stays as it was.
     *
     * @param array<string, mixed> $input
     * @return array{name: string, basis: string, amount: float}|null
     * @throws InputValidationException when the new person has no full birth date
     */
    private function repriceInsuranceItem(string $eventId, string $itemName, string $competitorId, array $input): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT iv.id FROM event_insurance_variants eiv
             INNER JOIN insurance_settings iv ON iv.id = eiv.insurance_variant_id
             WHERE eiv.event_id = :eventId AND iv.active = 1 AND CONCAT('NNW ', iv.name) = :name LIMIT 1"
        );
        $stmt->execute(['eventId' => $eventId, 'name' => $itemName]);
        $variantId = $stmt->fetchColumn();
        if ($variantId === false) {
            return null;
        }
        $eventDate = $this->pdo->prepare('SELECT date FROM events WHERE id = :id');
        $eventDate->execute(['id' => $eventId]);
        $newPerson = is_array($input['newPerson'] ?? null) ? $input['newPerson'] : [];

        return $this->insuranceSettlementItem(
            [
                'eventId' => $eventId,
                'insuranceVariantId' => (string) $variantId,
                'competitorId' => $competitorId,
                'birthDate' => (string) ($newPerson['birthDate'] ?? ''),
            ],
            (string) $eventDate->fetchColumn()
        );
    }
}
