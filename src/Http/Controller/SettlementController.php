<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ZmierzymyCzas\Domain\Payments\SettlementReport;
use ZmierzymyCzas\Domain\Registrations\StartListExportFormatter;
use ZmierzymyCzas\Http\DomainErrorResponder;
use ZmierzymyCzas\Http\Support\ActivityLogger;
use ZmierzymyCzas\Http\Support\RequestInput;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\JsonResponder;

/**
 * Settlement report (gateway route): gateway payments minus refunds minus commission = amount
 * to pay out manually to the organizer. Admin-only. Amounts in grosze. CSV/XLSX export uses the same
 * machinery as the start-list export.
 */
final class SettlementController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ActivityLogger $activity,
    ) {
    }

    public function report(Request $request, Response $response): Response
    {
        $settlementReport = new SettlementReport($this->pdo, max(0, (int) Env::get('SETTLEMENT_COMMISSION_BPS', 0)));
        $parsed = $this->parseQuery($request->getQueryParams());
        if (isset($parsed['error'])) {
            return JsonResponder::error($response, $parsed['error'][0], $parsed['error'][1], 422);
        }

        return JsonResponder::ok($response, $settlementReport->report($parsed['dateFrom'], $parsed['dateTo'], $parsed['eventId']));
    }

    public function export(Request $request, Response $response): Response
    {
        $settlementReport = new SettlementReport($this->pdo, max(0, (int) Env::get('SETTLEMENT_COMMISSION_BPS', 0)));
        $params = $request->getQueryParams();
        $format = strtolower((string) ($params['format'] ?? 'csv'));
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            return JsonResponder::error($response, 'validation_error', 'Nieobsługiwany format eksportu. Dozwolone: csv, xlsx.', 422);
        }
        $parsed = $this->parseQuery($params);
        if (isset($parsed['error'])) {
            return JsonResponder::error($response, $parsed['error'][0], $parsed['error'][1], 422);
        }

        $report = $settlementReport->report($parsed['dateFrom'], $parsed['dateTo'], $parsed['eventId']);
        $export = SettlementReport::exportRows($report['lines'], $report['summary']);
        try {
            $fileContent = $format === 'xlsx'
                ? StartListExportFormatter::toXlsx($export['columns'], $export['rows'])
                : StartListExportFormatter::toCsv($export['columns'], $export['rows']);
        } catch (\RuntimeException $e) {
            return DomainErrorResponder::respond($response, $e, ['xlsx_not_supported']);
        }

        $this->activity->log(
            RequestInput::auth($request),
            'settlement_exported',
            sprintf('Eksport raportu rozliczeniowego (%s) za okres %s - %s', strtoupper($format), $parsed['dateFrom'], $parsed['dateTo']),
            'settlement',
            $parsed['eventId'] ?? 'all',
            ''
        );

        $filenameBase = 'rozliczenie-' . $parsed['dateFrom'] . '_' . $parsed['dateTo'];
        $response->getBody()->write($fileContent);
        if ($format === 'xlsx') {
            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filenameBase . '.xlsx"');
        }

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=windows-1250')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filenameBase . '.csv"');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseQuery(array $params): array
    {
        $dateFrom = trim((string) ($params['dateFrom'] ?? ''));
        $dateTo = trim((string) ($params['dateTo'] ?? ''));
        foreach (['dateFrom' => $dateFrom, 'dateTo' => $dateTo] as $field => $value) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                return ['error' => ['validation_error', 'Podaj okres rozliczenia w formacie RRRR-MM-DD (' . $field . ').']];
            }
        }
        if ($dateFrom > $dateTo) {
            return ['error' => ['validation_error', 'Data początkowa nie może być późniejsza niż końcowa.']];
        }
        $eventId = trim((string) ($params['eventId'] ?? ''));

        return ['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'eventId' => $eventId !== '' ? $eventId : null];
    }
}
