<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Http\Access\EventAccess;
use ZmierzymyCzas\Http\Support\RequestInput;
use ZmierzymyCzas\Support\JsonResponder;

/** Start numbers (bib numbers) of an event's registrations. */
final class BibNumberController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly RegistrationRepository $registrations,
        private readonly EventAccess $eventAccess,
    ) {
    }

    /**
     * Bulk "assign start numbers": next free number inside each distance's range.
     *
     * @param array<string, string> $args
     */
    public function assign(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $eventId = (string) $args['eventId'];
        if (!$this->eventAccess->canManage($this->events->find($eventId), $auth)) {
            return JsonResponder::forbidden($response);
        }
        $payload = RequestInput::body($request);
        $distanceIds = $payload['distanceIds'] ?? null;
        if ($distanceIds !== null && (!is_array($distanceIds) || !array_is_list($distanceIds) || array_filter($distanceIds, static fn ($id): bool => !is_string($id)) !== [])) {
            return JsonResponder::error($response, 'validation_error', 'Pole distanceIds musi być listą identyfikatorów dystansów.', 422, ['field' => 'distanceIds']);
        }
        $order = (string) ($payload['order'] ?? 'registered');
        if (!in_array($order, ['registered', 'name'], true)) {
            return JsonResponder::error($response, 'validation_error', 'Pole order musi mieć wartość registered albo name.', 422, ['field' => 'order']);
        }
        $paidOnly = (bool) ($payload['paidOnly'] ?? true);
        return JsonResponder::ok($response, $this->registrations->bibNumbers()->assign($eventId, $distanceIds, $order, $paidOnly));
    }
}
