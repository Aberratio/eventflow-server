<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments;

use ZmierzymyCzas\Domain\Repository;

/**
 * Raport rozliczeniowy trasy A (spec 13): pieniadze zebrane bramka Tpay/PayNow leza na koncie
 * platformy, a wyplata do organizatora jest recznym przelewem admina na podstawie tego raportu.
 *
 * Wszystkie kwoty sa w groszach (int). Linie to wplaty (`registration_gateway_transactions`
 * ze statusem `paid`, wg `processed_at`) i zwroty trasy A (`registration_refunds` ze statusem
 * `refunded` i trasa `gateway`, wg `completed_at`) w zadanym okresie. Do wyplaty = wplaty
 * - zwroty - prowizja platformy. Spec: platforma nie pobiera prowizji od oplaty startowej,
 * wiec domyslna stawka to 0 bps (`SETTLEMENT_COMMISSION_BPS`); prowizja od zwrotu jest
 * cofana proporcjonalnie.
 */
final class SettlementReport extends Repository
{
    /** @param int $commissionBps prowizja platformy w punktach bazowych (100 bps = 1%) */
    public function __construct(\PDO $pdo, private readonly int $commissionBps = 0)
    {
        parent::__construct($pdo);
    }

    public function commissionBps(): int
    {
        return $this->commissionBps;
    }

    /**
     * @return array{
     *   period: array{dateFrom: string, dateTo: string},
     *   commissionRateBps: int,
     *   summary: array<string, int>,
     *   events: array<int, array<string, mixed>>,
     *   lines: array<int, array<string, mixed>>
     * }
     */
    public function report(string $dateFrom, string $dateTo, ?string $eventId = null, bool $withLines = true): array
    {
        $lines = $this->lines($dateFrom, $dateTo, $eventId);

        /** @var array<string, array<string, mixed>> $events */
        $events = [];
        $summary = self::emptyTotals();
        foreach ($lines as $line) {
            $key = (string) $line['eventId'];
            $events[$key] ??= ['eventId' => $key, 'eventName' => $line['eventName'], 'organizerName' => $line['organizerName']] + self::emptyTotals();
            self::accumulate($events[$key], $line);
            self::accumulate($summary, $line);
        }
        foreach ($events as $key => $event) {
            $events[$key]['netMinor'] = self::net($event);
        }
        $summary['netMinor'] = self::net($summary);
        uasort($events, static fn (array $a, array $b): int => [$b['netMinor'], $a['eventName']] <=> [$a['netMinor'], $b['eventName']]);

        return [
            'period' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
            'commissionRateBps' => $this->commissionBps,
            'summary' => $summary,
            'events' => array_values($events),
            'lines' => $withLines ? $lines : [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function lines(string $dateFrom, string $dateTo, ?string $eventId = null): array
    {
        $toExclusive = (new \DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
        $eventSql = $eventId !== null ? ' AND r.event_id = :eventId' : '';
        $params = ['from' => $dateFrom, 'to' => $toExclusive] + ($eventId !== null ? ['eventId' => $eventId] : []);

        $payments = $this->pdo->prepare(
            "SELECT t.transaction_id AS reference, t.gateway, t.amount, t.processed_at AS occurredAt,
                    r.id AS registrationId, r.bib_number AS bibNumber, r.user_name AS participantName,
                    r.event_id AS eventId, r.event_name AS eventName, e.organizer_name AS organizerName
             FROM registration_gateway_transactions t
             INNER JOIN registrations r ON r.id = t.registration_id
             LEFT JOIN events e ON e.id = r.event_id
             WHERE t.status = 'paid' AND t.processed_at >= :from AND t.processed_at < :to{$eventSql}"
        );
        $payments->execute($params);

        $refunds = $this->pdo->prepare(
            "SELECT rf.registration_id AS reference, 'gateway' AS gateway, rf.amount, rf.completed_at AS occurredAt,
                    r.id AS registrationId, r.bib_number AS bibNumber, r.user_name AS participantName,
                    r.event_id AS eventId, r.event_name AS eventName, e.organizer_name AS organizerName
             FROM registration_refunds rf
             INNER JOIN registrations r ON r.id = rf.registration_id
             LEFT JOIN events e ON e.id = r.event_id
             WHERE rf.status = 'refunded' AND rf.route = 'gateway' AND rf.amount IS NOT NULL
               AND rf.completed_at >= :from AND rf.completed_at < :to{$eventSql}"
        );
        $refunds->execute($params);

        $lines = [];
        foreach ([['payment', $payments], ['refund', $refunds]] as [$type, $stmt]) {
            foreach ($stmt->fetchAll() as $row) {
                $amount = GatewayAmount::toGrosze((string) $row['amount']) ?? 0;
                $commission = intdiv($amount * $this->commissionBps + 5000, 10000);
                $lines[] = [
                    'type' => $type,
                    'occurredAt' => (string) $row['occurredAt'],
                    'eventId' => (string) $row['eventId'],
                    'eventName' => (string) $row['eventName'],
                    'organizerName' => (string) ($row['organizerName'] ?? ''),
                    'registrationId' => (string) $row['registrationId'],
                    'bibNumber' => $row['bibNumber'] !== null ? (string) $row['bibNumber'] : null,
                    'participantName' => (string) $row['participantName'],
                    'gateway' => (string) $row['gateway'],
                    'reference' => (string) $row['reference'],
                    'amountMinor' => $amount,
                    'commissionMinor' => $commission,
                ];
            }
        }
        usort($lines, static fn (array $a, array $b): int => [$a['eventName'], $a['occurredAt'], $a['type'], $a['reference']]
            <=> [$b['eventName'], $b['occurredAt'], $b['type'], $b['reference']]);

        return $lines;
    }

    /**
     * Kolumny i wiersze dla eksportu CSV/XLSX (ten sam format co eksport listy startowej);
     * kwoty w PLN z przecinkiem, linia podsumowania na koncu.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, int> $summary
     * @return array{columns: array<int, array{key: string, label: string}>, rows: array<int, array<string, mixed>>}
     */
    public static function exportRows(array $lines, array $summary): array
    {
        $columns = [
            ['key' => 'event', 'label' => 'Wydarzenie'],
            ['key' => 'organizer', 'label' => 'Organizator'],
            ['key' => 'date', 'label' => 'Data'],
            ['key' => 'type', 'label' => 'Typ'],
            ['key' => 'participant', 'label' => 'Uczestnik'],
            ['key' => 'bib', 'label' => 'Numer startowy'],
            ['key' => 'gateway', 'label' => 'Bramka'],
            ['key' => 'reference', 'label' => 'Identyfikator'],
            ['key' => 'amount', 'label' => 'Kwota (PLN)'],
            ['key' => 'commission', 'label' => 'Prowizja (PLN)'],
        ];
        $rows = [];
        foreach ($lines as $line) {
            $sign = $line['type'] === 'refund' ? -1 : 1;
            $rows[] = [
                'event' => $line['eventName'],
                'organizer' => $line['organizerName'],
                'date' => $line['occurredAt'],
                'type' => $line['type'] === 'refund' ? 'Zwrot' : 'Wpłata',
                'participant' => $line['participantName'],
                'bib' => $line['bibNumber'] ?? '',
                'gateway' => $line['gateway'],
                'reference' => $line['reference'],
                'amount' => self::pln($sign * $line['amountMinor']),
                'commission' => self::pln($sign * $line['commissionMinor']),
            ];
        }
        $rows[] = ['event' => 'RAZEM wpłaty', 'amount' => self::pln($summary['grossMinor'])];
        $rows[] = ['event' => 'RAZEM zwroty', 'amount' => self::pln(-$summary['refundsMinor'])];
        $rows[] = ['event' => 'RAZEM prowizja', 'amount' => self::pln(-$summary['commissionMinor'])];
        $rows[] = ['event' => 'DO WYPŁATY', 'amount' => self::pln($summary['netMinor'])];

        return ['columns' => $columns, 'rows' => $rows];
    }

    private static function pln(int $minor): string
    {
        return ($minor < 0 ? '-' : '') . intdiv(abs($minor), 100) . ',' . str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string, int> */
    private static function emptyTotals(): array
    {
        return ['paymentsCount' => 0, 'refundsCount' => 0, 'grossMinor' => 0, 'refundsMinor' => 0, 'commissionMinor' => 0, 'netMinor' => 0];
    }

    /**
     * @param array<string, mixed> $totals
     * @param array<string, mixed> $line
     */
    private static function accumulate(array &$totals, array $line): void
    {
        if ($line['type'] === 'payment') {
            $totals['paymentsCount']++;
            $totals['grossMinor'] += $line['amountMinor'];
            $totals['commissionMinor'] += $line['commissionMinor'];
        } else {
            $totals['refundsCount']++;
            $totals['refundsMinor'] += $line['amountMinor'];
            $totals['commissionMinor'] -= $line['commissionMinor'];
        }
    }

    /** @param array<string, mixed> $totals */
    private static function net(array $totals): int
    {
        return $totals['grossMinor'] - $totals['refundsMinor'] - $totals['commissionMinor'];
    }
}
