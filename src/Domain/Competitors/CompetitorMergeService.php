<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

use PDO;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;

/**
 * E8.1 (issue 22, "Scalanie i przejmowanie profili Zawodnika"): trzecia warstwa
 * obrony przed duplikatami Zawodnika — wyłącznie Moderator/Admin (routes.php
 * gates every endpoint here with AuthMiddleware ['admin', 'moderator']; this
 * class does not re-check authorization, same division of labour as
 * RegistrationRepository not knowing about the caller's role).
 *
 * Scope note (spec Answer, "Czego to rozstrzygnięcie świadomie nie dotyka"):
 * this is ONLY the third defense layer (merging two existing profiles).
 * "Przejęcie profilu przez konto" (claiming, e-mail verification) is a
 * different, already-specified mechanism and is not built here.
 *
 * Identity-chain finding this service leans on (see comparison()'s docblock
 * and CHALLENGE_PARTICIPATIONS section below): `challenge_participations` and
 * `challenge_entries` are keyed by `user_id` (Konto), not `competitor_id`.
 * Two different Zawodnik profiles can never share a `user_id`
 * (`competitors.user_id` is UNIQUE, migration 027) — so merging two profiles
 * never needs to, and structurally cannot, repoint challenge history: each
 * profile's own Konto (if it has one) keeps its own separate challenge
 * standing untouched by a Zawodnik-level merge. Only account-level claiming
 * (out of scope here) could ever change that, and only for the merged
 * *identity* going forward, not retroactively for challenge entries already
 * tied to a distinct login.
 */
final class CompetitorMergeService
{
    /** Fields a moderator can pick per-side when the two profiles disagree. */
    private const COMPARABLE_FIELDS = ['firstName', 'lastName', 'birthDate', 'gender', 'email', 'phone', 'club'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CompetitorRepository $competitors,
        private readonly CareRepository $care,
        private readonly RegistrationRepository $registrations,
        private readonly ResultRepository $results,
        private readonly ChallengeRepository $challenges,
        private readonly AdminRepository $admin,
    ) {
    }

    /**
     * Spec Answer §2 step 2: both profiles, side by side, with everything each
     * one carries. Lifetime records are deliberately read here via
     * `ResultRepository::lifetimeRecordsForCompetitor()` — confirmed (see that
     * method's own docblock) to be a live, derived-on-read query over
     * results/registrations, not a stored per-competitor row — so merging
     * only ever needs to repoint `registrations.competitor_id` for these to
     * recompute automatically; there is no separate lifetime-record
     * repointing step anywhere in this class.
     *
     * @return array{competitors: array<int, array<string, mixed>>, conflicts: array<int, array<string, mixed>>}
     */
    public function comparison(string $idA, string $idB): array
    {
        $a = $this->competitors->find($idA);
        $b = $this->competitors->find($idB);
        if ($a === null || $b === null) {
            throw new \RuntimeException('competitor_not_found');
        }

        return [
            'competitors' => [$this->profileBundle($a), $this->profileBundle($b)],
            'conflicts' => $this->detectConflicts($idA, $idB),
        ];
    }

    /** @return array<string, mixed> */
    private function profileBundle(array $competitor): array
    {
        $competitorId = (string) $competitor['id'];
        $userId = $competitor['userId'] ?? null;

        return [
            'competitor' => $competitor,
            'registrations' => $this->registrations->listByCompetitor($competitorId),
            'lifetimeRecords' => $this->results->lifetimeRecordsForCompetitor($competitorId),
            'challengeParticipations' => $userId !== null ? $this->challenges->participations(null, (string) $userId) : [],
            'caregivers' => $this->care->listCaregivers($competitorId),
        ];
    }

    /**
     * Spec Answer §2 step 6: two active Zapisy (not cancelled/removed/expired)
     * for the same Dystans of the same Wydarzenie, one from each profile.
     * Direction-agnostic on purpose — comparison() is called before the
     * moderator has picked a survivor, so conflicts are reported per-pair,
     * not yet as "survivor's vs absorbed's".
     *
     * @return array<int, array<string, mixed>>
     */
    private function detectConflicts(string $idA, string $idB): array
    {
        $active = ['pending', 'confirmed'];
        $byKeyA = [];
        foreach ($this->registrations->listByCompetitor($idA) as $reg) {
            if (in_array($reg['status'], $active, true)) {
                $byKeyA[$reg['eventId'] . '::' . $reg['distanceId']] = $reg;
            }
        }

        $conflicts = [];
        foreach ($this->registrations->listByCompetitor($idB) as $reg) {
            if (!in_array($reg['status'], $active, true)) {
                continue;
            }
            $key = $reg['eventId'] . '::' . $reg['distanceId'];
            if (!isset($byKeyA[$key])) {
                continue;
            }

            $conflicts[] = [
                'eventId' => $reg['eventId'],
                'eventName' => $reg['eventName'],
                'distanceId' => $reg['distanceId'],
                'distanceName' => $reg['distanceName'],
                'registrationIds' => [(string) $byKeyA[$key]['id'], (string) $reg['id']],
            ];
        }

        return $conflicts;
    }

    /**
     * Executes the merge, or pauses it. Spec Answer §2 step 6: a detected
     * registration conflict PAUSES automatic merging for that pair — this
     * method returns a `status: 'conflict_pending'` result (no mutation at
     * all, nothing partially applied) whenever a conflict exists without a
     * matching resolution in $conflictResolutions, rather than guessing.
     *
     * @param array<string, mixed> $auth JWT claims of the acting Moderator/Admin (for the activity log)
     * @param array<string, string> $fieldDecisions field => 'survivor'|'absorbed', required for every COMPARABLE_FIELDS entry where the two profiles actually differ
     * @param array<int, array{eventId: string, distanceId: string, winnerRegistrationId: string}> $conflictResolutions
     * @return array<string, mixed>
     */
    public function merge(array $auth, string $survivorId, string $absorbedId, array $fieldDecisions, array $conflictResolutions = []): array
    {
        if ($survivorId === $absorbedId) {
            throw new \InvalidArgumentException('cannot_merge_competitor_with_itself');
        }

        $survivor = $this->competitors->find($survivorId);
        $absorbed = $this->competitors->find($absorbedId);
        if ($survivor === null || $absorbed === null) {
            throw new \RuntimeException('competitor_not_found');
        }
        if (($survivor['status'] ?? 'active') !== 'active' || ($absorbed['status'] ?? 'active') !== 'active') {
            throw new \RuntimeException('competitor_already_merged');
        }

        $missingDecisions = $this->missingFieldDecisions($survivor, $absorbed, $fieldDecisions);
        if ($missingDecisions !== []) {
            return ['status' => 'field_decisions_required', 'fields' => $missingDecisions];
        }

        $conflicts = $this->detectConflicts($survivorId, $absorbedId);
        $resolutionByKey = [];
        foreach ($conflictResolutions as $resolution) {
            $key = (string) ($resolution['eventId'] ?? '') . '::' . (string) ($resolution['distanceId'] ?? '');
            $resolutionByKey[$key] = (string) ($resolution['winnerRegistrationId'] ?? '');
        }

        $unresolved = array_values(array_filter(
            $conflicts,
            static fn (array $c): bool => !isset($resolutionByKey[$c['eventId'] . '::' . $c['distanceId']])
        ));
        if ($unresolved !== []) {
            return ['status' => 'conflict_pending', 'conflicts' => $unresolved];
        }

        // Full pre-merge snapshot — spec Answer §4/§5: this is the ONLY place
        // the absorbed profile's state survives once the merge lands (no
        // "undo" button is built; a bad merge is corrected by support, by
        // hand, from exactly this record).
        $snapshot = [
            'survivorBefore' => $survivor,
            'absorbedBefore' => $absorbed,
            'survivorLinkedBefore' => $this->profileBundle($survivor),
            'absorbedLinkedBefore' => $this->profileBundle($absorbed),
            'fieldDecisions' => $this->describeFieldDecisions($survivor, $absorbed, $fieldDecisions),
            'conflictResolutions' => $conflicts === [] ? [] : $this->describeConflictResolutions($conflicts, $resolutionByKey),
        ];

        $rejectedRegistrationIds = array_values($resolutionByKey === []
            ? []
            : array_map(
                static fn (array $c): string => $c['registrationIds'][0] === $resolutionByKey[$c['eventId'] . '::' . $c['distanceId']]
                    ? $c['registrationIds'][1]
                    : $c['registrationIds'][0],
                $conflicts
            ));

        $this->pdo->beginTransaction();
        try {
            $fieldUpdates = $this->resolvedFieldValues($survivor, $absorbed, $fieldDecisions);
            if ($fieldUpdates !== []) {
                $this->competitors->update($survivorId, $fieldUpdates);
            }

            $repointedRegistrationIds = [];
            foreach ($this->registrations->listByCompetitor($absorbedId) as $reg) {
                $this->registrations->repointCompetitor((string) $reg['id'], $survivorId);
                $repointedRegistrationIds[] = (string) $reg['id'];
            }

            $this->care->repointForMerge($absorbedId, $survivorId);

            if (!empty($absorbed['identityCode'])) {
                $this->competitors->addIdentityCodeAlias((string) $absorbed['identityCode'], $survivorId);
            }

            $this->competitors->markMerged($absorbedId, $survivorId);

            // R-08: otwarte zgłoszenia duplikatu tej pary są tym samym rozstrzygnięte.
            (new CompetitorDuplicateReportRepository($this->pdo))->markMergedForPair($survivorId, $absorbedId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // The merge itself is already fully committed at this point — the
        // activity-log snapshot is the ticket's ONLY recovery mechanism (no
        // "undo" button by design), so it must not depend on anything that
        // can still fail below. Written BEFORE the refund/cancel loop on
        // purpose: every value it needs (survivor/absorbed before-state,
        // field decisions, conflict resolutions, which registrations were
        // repointed/rejected, whether the identity code was aliased) is
        // already known here and none of it depends on a refund/cancel call
        // succeeding. See the refund loop below for what happens if one of
        // those calls fails anyway — this log entry is unaffected either way.
        $actingId = (string) ($auth['sub'] ?? '');
        $this->admin->insertActivityLog([
            'userId' => $actingId,
            'action' => 'competitor_merged',
            'description' => sprintf(
                'Scalono profil Zawodnika "%s %s" w "%s %s"',
                $absorbed['firstName'],
                $absorbed['lastName'],
                $survivor['firstName'],
                $survivor['lastName']
            ),
            'targetType' => 'competitor',
            'targetId' => $survivorId,
            'targetName' => trim($survivor['firstName'] . ' ' . $survivor['lastName']),
            'metadata' => $snapshot + [
                'repointedRegistrationIds' => $repointedRegistrationIds,
                'repointedCareRelations' => true,
                'rejectedRegistrationIds' => $rejectedRegistrationIds,
                'identityCodeAliased' => !empty($absorbed['identityCode']),
            ],
        ]);

        // Spec Answer §2 step 6: the rejected side of a conflicting pair "goes
        // through the standard cancellation/refund path (E1.5), never
        // silently disappears" — reusing requestRefund()/approveRefund() for
        // an already-paid Zapis, cancel() otherwise. Deliberately run AFTER
        // the merge transaction commits, not nested inside it: these methods
        // manage their own PDO transactions and PDO does not support nesting.
        //
        // The merge itself has ALREADY committed and been logged above by
        // this point, so a failure here (concurrent state change on that
        // registration, a DB hiccup, anything) must never be allowed to read
        // back to the caller as "the merge failed" — that would be false,
        // and previously produced a misleading 404 via routes.php's generic
        // \RuntimeException catch while the merge had in fact already
        // succeeded. Instead: collect failures per-registration and report
        // them honestly alongside the successful merge result.
        $refundFailures = [];
        foreach ($rejectedRegistrationIds as $rejectedId) {
            try {
                $this->rejectConflictingRegistration($rejectedId, $actingId);
            } catch (\Throwable $e) {
                $refundFailures[] = [
                    'registrationId' => $rejectedId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($refundFailures !== []) {
            $this->admin->insertActivityLog([
                'userId' => $actingId,
                'action' => 'competitor_merge_refund_issue',
                'description' => sprintf(
                    'Scalenie "%s %s" -> "%s %s" powiodło się, ale rozstrzygnięcie %d konfliktowego(-ych) Zapisu(-ów) wymaga ręcznej interwencji',
                    $absorbed['firstName'],
                    $absorbed['lastName'],
                    $survivor['firstName'],
                    $survivor['lastName'],
                    count($refundFailures)
                ),
                'targetType' => 'competitor',
                'targetId' => $survivorId,
                'targetName' => trim($survivor['firstName'] . ' ' . $survivor['lastName']),
                'metadata' => ['refundFailures' => $refundFailures],
            ]);
        }

        return [
            'status' => 'merged',
            'survivor' => $this->competitors->find($survivorId),
            'absorbed' => $this->competitors->find($absorbedId),
            'refundFailures' => $refundFailures,
        ];
    }

    private function rejectConflictingRegistration(string $registrationId, string $actingId): void
    {
        $registration = $this->registrations->find($registrationId);
        if ($registration === null) {
            return;
        }

        if ($registration['status'] === 'confirmed') {
            $this->registrations->requestRefund($registrationId, $actingId, 'merge_conflict');
            $this->registrations->approveRefund($registrationId, $actingId);
            return;
        }

        if (in_array($registration['status'], ['pending'], true)) {
            $this->registrations->cancel($registrationId);
        }
    }

    /** @return array<int, string> */
    private function missingFieldDecisions(array $survivor, array $absorbed, array $fieldDecisions): array
    {
        $missing = [];
        foreach (self::COMPARABLE_FIELDS as $field) {
            if ($this->normalized($survivor[$field] ?? null) === $this->normalized($absorbed[$field] ?? null)) {
                continue;
            }
            $decision = $fieldDecisions[$field] ?? null;
            if (!in_array($decision, ['survivor', 'absorbed'], true)) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /** @return array<string, mixed> */
    private function resolvedFieldValues(array $survivor, array $absorbed, array $fieldDecisions): array
    {
        $updates = [];
        foreach (self::COMPARABLE_FIELDS as $field) {
            $decision = $fieldDecisions[$field] ?? null;
            if ($decision === 'absorbed') {
                $updates[$field] = $absorbed[$field] ?? null;
            }
            // 'survivor' (explicit or default, for fields that don't differ) needs no write.
        }
        return $updates;
    }

    /**
     * Spec Answer §2 step 4: "obie wartości zostają w logu, nawet ta
     * odrzucona" — for every field a decision applies to (differing or not),
     * record both candidate values and which one won, by name, not just id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function describeFieldDecisions(array $survivor, array $absorbed, array $fieldDecisions): array
    {
        $described = [];
        foreach (self::COMPARABLE_FIELDS as $field) {
            $survivorValue = $survivor[$field] ?? null;
            $absorbedValue = $absorbed[$field] ?? null;
            if ($this->normalized($survivorValue) === $this->normalized($absorbedValue)) {
                continue;
            }
            $described[] = [
                'field' => $field,
                'survivorValue' => $survivorValue,
                'absorbedValue' => $absorbedValue,
                'chosen' => in_array($fieldDecisions[$field] ?? null, ['survivor', 'absorbed'], true) ? $fieldDecisions[$field] : 'survivor',
            ];
        }
        return $described;
    }

    /** @return array<int, array<string, mixed>> */
    private function describeConflictResolutions(array $conflicts, array $resolutionByKey): array
    {
        $described = [];
        foreach ($conflicts as $conflict) {
            $key = $conflict['eventId'] . '::' . $conflict['distanceId'];
            $winner = $resolutionByKey[$key] ?? null;
            $loser = $winner !== null
                ? ($conflict['registrationIds'][0] === $winner ? $conflict['registrationIds'][1] : $conflict['registrationIds'][0])
                : null;
            $described[] = [
                'eventId' => $conflict['eventId'],
                'eventName' => $conflict['eventName'],
                'distanceId' => $conflict['distanceId'],
                'distanceName' => $conflict['distanceName'],
                'winnerRegistrationId' => $winner,
                'rejectedRegistrationId' => $loser,
            ];
        }
        return $described;
    }

    private function normalized(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
