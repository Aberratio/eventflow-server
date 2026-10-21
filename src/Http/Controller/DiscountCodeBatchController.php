<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ZmierzymyCzas\Domain\Events\DiscountCodeBatchRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\StartListExportFormatter;
use ZmierzymyCzas\Http\Access\EventAccess;
use ZmierzymyCzas\Http\Support\ActivityLogger;
use ZmierzymyCzas\Http\Support\RequestInput;
use ZmierzymyCzas\Support\JsonResponder;

/**
 * Discount code batches (a generator over event_discount_codes, not a second mechanism). Same
 * manage gate as the rest of the event-manager group (owner + admin roles; a foreign organizer
 * gets 403, a foreign/unknown batch id 404). The CSV hands out live codes, so a successful export
 * is activity-logged like the start-list export.
 */
final class DiscountCodeBatchController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly DiscountCodeBatchRepository $discountBatches,
        private readonly EventAccess $eventAccess,
        private readonly ActivityLogger $activity,
    ) {
    }

    /** @param array<string, string> $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $event = $this->events->find((string) $args['eventId']);
        if (!$this->eventAccess->canManage($event, $auth)) {
            return JsonResponder::forbidden($response);
        }
        $batch = $this->discountBatches->generate((string) $args['eventId'], RequestInput::body($request), (string) ($auth['sub'] ?? ''));
        $this->activity->log(
            $auth,
            'discount_batch_generated',
            sprintf('Wygenerowano partię kodów rabatowych "%s" (%d szt.)', $batch['label'], $batch['count']),
            'event',
            (string) $args['eventId'],
            (string) ($event['name'] ?? '')
        );
        return JsonResponder::ok($response, $batch, 'Partia kodów została wygenerowana.', 201);
    }

    /** @param array<string, string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        $event = $this->events->find((string) $args['eventId']);
        if (!$this->eventAccess->canManage($event, RequestInput::auth($request))) {
            return JsonResponder::forbidden($response);
        }
        return JsonResponder::ok($response, $this->discountBatches->listForEvent((string) $args['eventId']));
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $event = $this->events->find((string) $args['eventId']);
        if (!$this->eventAccess->canManage($event, RequestInput::auth($request))) {
            return JsonResponder::forbidden($response);
        }
        $batch = $this->discountBatches->find((string) $args['eventId'], (string) $args['batchId']);
        if ($batch === null) {
            return JsonResponder::error($response, 'not_found', 'Nie znaleziono partii kodów.', 404);
        }
        return JsonResponder::ok($response, $batch);
    }

    /** @param array<string, string> $args */
    public function export(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $event = $this->events->find((string) $args['eventId']);
        if (!$this->eventAccess->canManage($event, $auth)) {
            return JsonResponder::forbidden($response);
        }
        $batch = $this->discountBatches->find((string) $args['eventId'], (string) $args['batchId']);
        if ($batch === null) {
            return JsonResponder::error($response, 'not_found', 'Nie znaleziono partii kodów.', 404);
        }
        $export = DiscountCodeBatchRepository::exportRows($batch);
        $fileContent = StartListExportFormatter::toCsv($export['columns'], $export['rows']);
        $filenameBase = 'kody-rabatowe-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) ($batch['prefix'] !== '' ? $batch['prefix'] : 'partia')), '-');
        $this->activity->log(
            $auth,
            'discount_batch_exported',
            sprintf('Eksport partii kodów rabatowych "%s" (CSV)', $batch['label']),
            'event',
            (string) $args['eventId'],
            (string) ($event['name'] ?? '')
        );
        $response->getBody()->write($fileContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=windows-1250')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filenameBase . '.csv"');
    }
}
