<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Access;

use Psr\Http\Message\ResponseInterface as Response;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\JsonResponder;

/** Who may act on one registration: its owner, whoever registered it, or a manager of its event. */
final class RegistrationAccess
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EventRepository $events,
        private readonly EventAccess $eventAccess,
    ) {
    }

    /**
     * @param array<string, mixed>|null $registration
     * @param array<string, mixed> $auth
     */
    public function isOwner(?array $registration, array $auth): bool
    {
        if (!$registration) {
            return false;
        }
        $callerId = (string) ($auth['sub'] ?? '');
        return $callerId !== '' && (
            (string) $registration['userId'] === $callerId
            || (string) ($registration['registeredById'] ?? '') === $callerId
        );
    }

    /**
     * The single gate for any route addressing one registration by id. Loads the registration
     * and decides, or returns the ready-made error response. New routes that take a
     * registration id MUST go through this.
     *
     * @param array<string, mixed> $auth
     * @return array{registration: array<string, mixed>, isManager: bool}|Response
     */
    public function authorize(Response $response, string $registrationId, array $auth, bool $managerOnly = false, bool $money = false): array|Response
    {
        $registration = $this->registrations->find($registrationId);
        if ($registration === null) {
            return JsonResponder::error($response, 'not_found', 'Nie znaleziono zapisu.', 404);
        }
        $event = $this->events->find((string) $registration['eventId']);
        $isManager = $money ? $this->eventAccess->canManageMoney($event, $auth) : $this->eventAccess->canManage($event, $auth);
        if (!$isManager && ($managerOnly || !$this->isOwner($registration, $auth))) {
            return JsonResponder::forbidden($response);
        }
        return ['registration' => $registration, 'isManager' => $isManager];
    }
}
