<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorDuplicatePresenter;
use ZmierzymyCzas\Domain\Competitors\CompetitorDuplicateReportRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Http\Access\Roles;
use ZmierzymyCzas\Http\Support\RequestInput;
use ZmierzymyCzas\Support\JsonResponder;

/**
 * Potential duplicate Zawodnik profiles: the warning lookup, the reports filed by caregivers and
 * organizers, and the Moderator/Admin queue where a report is dismissed. A report merges nothing;
 * the merged status is set only by merging profiles.
 */
final class CompetitorDuplicateController
{
    public function __construct(
        private readonly CompetitorRepository $competitors,
        private readonly CareRepository $care,
        private readonly CompetitorDuplicateReportRepository $reports,
    ) {
    }

    /** Check potential duplicates by first name + last name + date of birth (a warning, not a block). */
    public function find(Request $request, Response $response): Response
    {
        $filters = RequestInput::query($request);
        $firstName = trim((string) ($filters['firstName'] ?? ''));
        $lastName = trim((string) ($filters['lastName'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            return JsonResponder::ok($response, []);
        }
        $birthDate = ($filters['birthDate'] ?? '') !== '' ? (string) $filters['birthDate'] : null;
        return JsonResponder::ok($response, CompetitorDuplicatePresenter::present($this->competitors->findDuplicates($firstName, $lastName, $birthDate)));
    }

    /**
     * A caregiver (Opieka / own profile) or organizer/staff reports that profile {id} is a
     * duplicate of another. The report itself merges nothing - a Moderator/Admin sees it in the
     * GET /admin/competitors/duplicate-reports queue next to profile merging.
     *
     * @param array<string, string> $args
     */
    public function report(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $kontoId = (string) ($auth['sub'] ?? '');
        $role = (string) ($auth['role'] ?? '');
        $id = (string) ($args['id'] ?? '');

        if ($this->competitors->find($id) === null) {
            return JsonResponder::error($response, 'not_found', 'Zawodnik nie istnieje.', 404);
        }
        // Caregiver - their own wards; organizer - only a Zawodnik with a Zapis on their event
        // (canManageEvent is based on organizerId) or an active Co-manager of such an event
        // (any global role); Moderator/Admin - anyone.
        $allowed = in_array($role, Roles::STAFF, true)
            || $this->care->canRegister($kontoId, $id)
            || ($role === 'organizer' && $this->reports->competitorRegisteredOnOrganizersEvent($id, $kontoId))
            || $this->reports->competitorRegisteredOnCoManagedEvent($id, $kontoId);
        if (!$allowed) {
            return JsonResponder::error($response, 'forbidden', 'Nie masz Opieki nad tym Zawodnikiem ani Zapisu na Twoim wydarzeniu.', 403);
        }

        $data = RequestInput::body($request);
        $duplicateOfId = trim((string) ($data['duplicateOfId'] ?? ''));
        if ($duplicateOfId === '' || $duplicateOfId === $id) {
            return JsonResponder::error($response, 'validation_error', 'Wskaż inny profil Zawodnika jako duplikat (duplicateOfId).', 422);
        }
        if ($this->competitors->find($duplicateOfId) === null) {
            return JsonResponder::error($response, 'not_found', 'Wskazany profil Zawodnika nie istnieje.', 404);
        }

        $report = $this->reports
            ->create($id, $duplicateOfId, $kontoId, $role, (string) ($data['note'] ?? ''));

        return JsonResponder::ok($response, $report, 'Zgłoszenie duplikatu zostało przekazane do weryfikacji.', 201);
    }

    /** Queue of open duplicate reports - Moderator/Admin only, like merging. */
    public function queue(Request $request, Response $response): Response
    {
        $status = (string) (RequestInput::query($request)['status'] ?? 'open');
        if (!in_array($status, ['open', 'rejected', 'merged', 'all'], true)) {
            return JsonResponder::error($response, 'validation_error', 'status: open, rejected, merged lub all.', 422);
        }

        return JsonResponder::ok($response, $this->reports->list($status));
    }

    /**
     * Dismissing a report (the merged status is set only by merging profiles).
     *
     * @param array<string, string> $args
     */
    public function dismiss(Request $request, Response $response, array $args): Response
    {
        $auth = RequestInput::auth($request);
        $data = RequestInput::body($request);
        if ((string) ($data['status'] ?? '') !== 'rejected') {
            return JsonResponder::error($response, 'validation_error', 'Dozwolona zmiana: status = rejected.', 422);
        }
        try {
            $report = $this->reports
                ->reject((string) ($args['id'] ?? ''), (string) ($auth['sub'] ?? ''), (string) ($data['reason'] ?? ''));
        } catch (\DomainException $e) {
            return JsonResponder::error($response, 'report_already_resolved', 'Zgłoszenie zostało już rozstrzygnięte.', 409);
        }
        if ($report === null) {
            return JsonResponder::error($response, 'not_found', 'Zgłoszenie nie istnieje.', 404);
        }

        return JsonResponder::ok($response, $report);
    }
}
