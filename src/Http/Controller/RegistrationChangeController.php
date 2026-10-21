<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\DistanceChangeRejected;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Registrations\SelfServicePolicy;
use ZmierzymyCzas\Http\Access\RegistrationAccess;
use ZmierzymyCzas\Http\Support\ActivityLogger;
use ZmierzymyCzas\Http\Support\RequestInput;
use ZmierzymyCzas\Support\JsonResponder;

/**
 * Changes to a single Zapis: cancellation, change of Dystans / Wariant opłaty and transfer to
 * another Zawodnik.
 */
final class RegistrationChangeController
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EventRepository $events,
        private readonly RegistrationAccess $registrationAccess,
        private readonly ActivityLogger $activity,
    ) {
    }

    /**
     * The Event's manager cancels whenever (as always); the owner only through
     * SelfServicePolicy - switch on, before the cut-off date. A paid Zapis cancelled by its owner opens
     * a refund request (organizer/admin approves); the change lands in the Zapis history.
     *
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $gate = $this->registrationAccess->authorize($response, (string) $args['id'], $auth);
        if ($gate instanceof Response) {
            return $gate;
        }
        $verdict = (new SelfServicePolicy())->evaluate((array) $this->events->find((string) $gate['registration']['eventId']), $gate['registration'], $gate['isManager']);
        if (!$verdict['allowed']) {
            $messages = [
                SelfServicePolicy::REASON_DISABLED => 'Organizator wyłączył samodzielną rezygnację z tego wydarzenia.',
                SelfServicePolicy::REASON_CLOSED => 'Termin samodzielnej rezygnacji minął.',
                SelfServicePolicy::REASON_EVENT_OVER => 'Wydarzenie jest już zamknięte dla zmian.',
                SelfServicePolicy::REASON_REGISTRATION_INACTIVE => 'Ten zapis nie jest już aktywny.',
            ];
            $reason = (string) $verdict['reason'];
            return JsonResponder::error($response, $reason, $messages[$reason] ?? 'Rezygnacja jest niedostępna.', 409, ['deadline' => $verdict['deadline']]);
        }
        $result = $this->registrations->cancelWithHistory((string) $args['id'], [
            'id' => (string) ($auth['sub'] ?? ''),
            'role' => (string) ($auth['role'] ?? ''),
            'isManager' => $gate['isManager'],
        ]);
        if ($result !== null && $result['changed']) {
            $this->activity->log(
                $auth,
                'registration_cancelled',
                $gate['isManager'] ? 'Anulowano zapis' : 'Rezygnacja z zapisu',
                'registration',
                (string) $args['id'],
                (string) ($gate['registration']['eventName'] ?? ''),
                ['refundRequested' => $result['refundRequested']]
            );
        }
        return JsonResponder::ok($response, $result['registration'] ?? null);
    }

    /**
     * Quote for a change of Dystans / Wariant opłaty; writes nothing.
     *
     * @param array<string, string> $args
     */
    public function quoteDistanceChange(Request $request, Response $response, array $args): Response
    {
        return $this->distanceChange($request, $response, $args, false);
    }

    /**
     * Change of Dystans / Wariant opłaty of a Zapis. The quote
     * and the change share one gate (SelfServicePolicy for the owner, the Event's manager always) and one
     * calculation. Owner + dearer Dystans -> 409 balance_due_requires_organizer.
     *
     * @param array<string, string> $args
     */
    public function changeDistance(Request $request, Response $response, array $args): Response
    {
        return $this->distanceChange($request, $response, $args, true);
    }

    /** @param array<string, string> $args */
    private function distanceChange(Request $request, Response $response, array $args, bool $commit): Response
    {
        $auth = RequestInput::auth($request);
        $gate = $this->registrationAccess->authorize($response, (string) $args['id'], $auth);
        if ($gate instanceof Response) {
            return $gate;
        }
        $payload = RequestInput::body($request);
        $distanceId = is_string($payload['distanceId'] ?? null) ? trim($payload['distanceId']) : '';
        if ($distanceId === '') {
            return JsonResponder::error($response, 'validation_error', 'distanceId is required', 422, ['field' => 'distanceId']);
        }
        foreach (['pricingVariantId', 'category'] as $optional) {
            if (isset($payload[$optional]) && !is_string($payload[$optional])) {
                return JsonResponder::error($response, 'validation_error', $optional . ' must be a string', 422, ['field' => $optional]);
            }
        }
        $verdict = (new SelfServicePolicy())->evaluate((array) $this->events->find((string) $gate['registration']['eventId']), $gate['registration'], $gate['isManager']);
        if (!$verdict['allowed']) {
            $messages = [
                SelfServicePolicy::REASON_DISABLED => 'Organizator wyłączył samodzielną zmianę zapisów w tym wydarzeniu.',
                SelfServicePolicy::REASON_CLOSED => 'Termin samodzielnej zmiany dystansu minął.',
                SelfServicePolicy::REASON_EVENT_OVER => 'Wydarzenie jest już zamknięte dla zmian.',
                SelfServicePolicy::REASON_REGISTRATION_INACTIVE => 'Ten zapis nie jest już aktywny.',
            ];
            $reason = (string) $verdict['reason'];
            return JsonResponder::error($response, $reason, $messages[$reason] ?? 'Zmiana dystansu jest niedostępna.', 409, ['deadline' => $verdict['deadline']]);
        }
        $actor = ['id' => (string) ($auth['sub'] ?? ''), 'role' => (string) ($auth['role'] ?? ''), 'isManager' => $gate['isManager']];
        $variantId = isset($payload['pricingVariantId']) ? (string) $payload['pricingVariantId'] : null;
        $category = isset($payload['category']) ? (string) $payload['category'] : null;
        try {
            if (!$commit) {
                return JsonResponder::ok($response, $this->registrations->quoteDistanceChange((string) $args['id'], $distanceId, $variantId, $category, $actor));
            }
            $result = $this->registrations->changeDistance((string) $args['id'], $distanceId, $variantId, $category, $actor);
        } catch (DistanceChangeRejected $e) {
            return JsonResponder::error($response, $e->reason, $e->getMessage(), $e->status, $e->details);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'registration_capacity_exceeded') {
                return JsonResponder::error($response, 'registration_capacity_exceeded', 'Wybrany dystans osiągnął limit uczestników.', 409);
            }
            if ($e->getMessage() === 'registration_distance_invalid') {
                return JsonResponder::error($response, 'registration_distance_invalid', 'Wybrany dystans nie istnieje w tym wydarzeniu.', 422);
            }
            throw $e;
        }
        $change = $result['change'];
        $this->activity->log(
            $auth,
            'registration_distance_changed',
            $gate['isManager'] ? 'Zmieniono dystans zapisu' : 'Zmiana dystansu zapisu',
            'registration',
            (string) $args['id'],
            (string) ($gate['registration']['eventName'] ?? ''),
            ['from' => $change['from'], 'to' => $change['to'], 'balanceDueMinor' => $change['balanceDueMinor'], 'refundDueMinor' => $change['refundDueMinor']]
        );
        return JsonResponder::ok($response, [
            ...$result['registration'],
            'balanceDueMinor' => $change['balanceDueMinor'],
            'refundDueMinor' => $change['refundDueMinor'],
            'refundRequested' => $result['refundRequested'],
            'change' => $change,
        ]);
    }

    /**
     * The Event's manager moves a Zapis to another
     * Zawodnik (existing `competitorId` or `newPerson`). Manager only - the owner gets 403. Shares the
     * person-swap core with the relay swap; a relay member is refused and pointed to that swap.
     * No money moves: balanceDueMinor / overpaymentMinor in the answer are informational.
     *
     * @param array<string, string> $args
     */
    public function transfer(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $gate = $this->registrationAccess->authorize($response, (string) $args['id'], $auth, true);
        if ($gate instanceof Response) {
            return $gate;
        }
        try {
            $result = $this->registrations->transferRegistration(
                (string) $args['id'],
                RequestInput::body($request),
                ['id' => (string) ($auth['sub'] ?? ''), 'email' => (string) ($auth['email'] ?? ''), 'role' => (string) ($auth['role'] ?? '')]
            );
        } catch (RuntimeException $e) {
            $errors = [
                'registration_not_found' => ['Nie znaleziono zapisu.', 404],
                'transfer_registration_inactive' => ['Ten zapis nie jest już aktywny - nie można go przenieść.', 409],
                'transfer_team_registration' => ['Zapisu w drużynie (sztafecie) nie przenosi się tym działaniem - zmień zawodnika w składzie drużyny (wymiana zawodnika).', 409],
                'team_swap_competitor_already_registered' => ['Ta osoba jest już zapisana na to wydarzenie.', 409],
                'team_swap_same_person' => ['To ten sam zawodnik, który jest już na tym zapisie.', 422],
                'team_swap_person_required' => ['Podaj istniejącego zawodnika (competitorId) albo dane nowej osoby (newPerson: imię i nazwisko).', 422],
                'team_swap_competitor_not_found' => ['Nie znaleziono zawodnika.', 404],
                'transfer_insurance_birth_date_missing' => ['Zapis ma ubezpieczenie NNW - nowa osoba musi mieć pełną datę urodzenia (birthDate), by przeliczyć składkę.', 422],
                'transfer_insurance_invalid' => ['Nie można przeliczyć ubezpieczenia NNW dla nowej osoby.', 422],
                'registration_distance_invalid' => ['Selected distance is invalid.', 422],
            ];
            if (isset($errors[$e->getMessage()])) {
                [$message, $status] = $errors[$e->getMessage()];
                return JsonResponder::error($response, $e->getMessage(), $message, $status);
            }
            throw $e;
        }
        $this->activity->log(
            $auth,
            'registration_transferred',
            sprintf('Przeniesiono zapis: %s -> %s', $result['from']['name'], $result['to']['name']),
            'registration',
            (string) $args['id'],
            (string) $result['to']['name'],
            ['from' => $result['from'], 'to' => $result['to'], 'balanceDueMinor' => $result['balanceDueMinor'], 'overpaymentMinor' => $result['overpaymentMinor']]
        );

        return JsonResponder::ok($response, [
            ...$result['registration'],
            'balanceDueMinor' => $result['balanceDueMinor'],
            'overpaymentMinor' => $result['overpaymentMinor'],
            'transfer' => ['from' => $result['from'], 'to' => $result['to'], 'changeId' => $result['changeId']],
        ]);
    }
}
