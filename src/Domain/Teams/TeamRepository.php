<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Teams;

use ZmierzymyCzas\Domain\Repository;

/**
 * E7.1 ("Cykl życia Drużyny: niepełny skład legalny, limit liczy Zapisy",
 * issue 23 Answer §1) — the real entity behind what used to be an
 * unconstrained `registrations.team_id`/`team_name` free-text pair.
 *
 * Deliberately thin: this class only owns team creation/lookup and the
 * derived "completeness" read. It does NOT own:
 *  - capacity/limit counting — that is `event_distances.registered_count`,
 *    maintained entirely by RegistrationRepository (E1.3) and untouched by
 *    this ticket; a team's completeness is a separate, purely informational
 *    number, never fed back into the Dystans limit;
 *  - joining/creating a Zapis into a team — RegistrationRepository::create()
 *    is the only writer of `registrations.team_id`, and is the one that
 *    calls into this class (create()/find()) while already holding the
 *    Dystans row lock, so every team-size check below is implicitly
 *    serialized by that same lock — no separate row lock is taken here.
 *  - E7.5's Kategoria specjalna — `special_category` exists on the table and
 *    is carried through find()/forDistance() so it is never lost, but
 *    nothing here ever sets it to anything but null.
 *
 * "Completeness" (Answer §1: "stan czysto pochodny, wyliczany z liczby
 * powiązanych Zapisów, nie osobne pole do ręcznego ustawiania") is computed
 * fresh on every read from a live COUNT(*) against `registrations`, using
 * the exact same "counted population" as RegistrationRepository's own
 * capacity counter (pending + confirmed — i.e. everything except
 * cancelled/removed/expired, see that class's NOT_COUNTED_STATUSES) so the
 * two numbers can never silently diverge into two different definitions of
 * "how many people are actually in this team right now".
 */
final class TeamRepository extends Repository
{
    /**
     * Mirrors RegistrationRepository::NOT_COUNTED_STATUSES — a Zapis in one
     * of these statuses no longer occupies a seat in the Dystans limit, and
     * by the same logic no longer counts as an occupied slot in its team's
     * roster either. Duplicated (not shared) because RegistrationRepository
     * has no public accessor for it and pulling it out into a shared
     * constant is a bigger refactor than this ticket's scope.
     */
    private const NOT_COUNTED_STATUSES = ['cancelled', 'removed', 'expired'];

    private const COLUMNS = 'id, event_id AS eventId, distance_id AS distanceId, name, '
        . 'created_by_user_id AS createdByUserId, special_category AS specialCategory, created_at AS createdAt';

    /**
     * @param array<string, mixed> $data eventId, distanceId, name, createdByUserId (optional)
     * @return array<string, mixed> the created team, with its (freshly zero) completeness attached
     */
    public function create(array $data): array
    {
        $id = $this->id();
        $this->insert('teams', [
            'id' => $id,
            'event_id' => $data['eventId'],
            'distance_id' => $data['distanceId'],
            'name' => $data['name'],
            'created_by_user_id' => $data['createdByUserId'] ?? null,
            'created_at' => date(DATE_ATOM),
        ]);

        return $this->find($id);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM teams WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->withCompleteness($row) : null;
    }

    /**
     * Every team for one Event+Dystans, most recently created first — the
     * "which teams can I join" read (E7.1 point 4/7; the client's "join an
     * existing team" step, `RegistrationForm.tsx`/`useRegistrationForm.ts`,
     * calls this via `GET /events/{eventId}/distances/{distanceId}/teams`
     * instead of its old purely client-side text-grouping heuristic).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forDistance(string $eventId, string $distanceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM teams WHERE event_id = :eventId AND distance_id = :distanceId ORDER BY created_at DESC'
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);

        return array_map(fn (array $row): array => $this->withCompleteness($row), $stmt->fetchAll());
    }

    /**
     * Non-cancelled/removed/expired Zapisy currently linked to this team —
     * the same number completeness is built from, exposed separately so
     * RegistrationRepository::create() can run the "is this team already at
     * relay_max_team_size?" join-time check without re-deriving completeness
     * for a team whose row it doesn't otherwise need.
     */
    public function memberCount(string $teamId): int
    {
        $placeholders = implode(', ', array_fill(0, count(self::NOT_COUNTED_STATUSES), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM registrations WHERE team_id = ? AND status NOT IN ({$placeholders})"
        );
        $stmt->execute([$teamId, ...self::NOT_COUNTED_STATUSES]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Attaches the derived `memberCount`/`minSize`/`maxSize`/`complete` to a
     * raw `teams` row — `minSize`/`maxSize` read straight off the team's own
     * Dystans (`event_distances.relay_min_team_size`/`relay_max_team_size`,
     * migration 024), so a completeness read is always judged against
     * whatever the organizer has configured right now, never a value copied
     * onto the team at creation time (which could go stale if the organizer
     * edits the Dystans afterward).
     *
     * `complete` means "reached the full roster" — `memberCount >=
     * relay_max_team_size` — matching the ticket's own worked example
     * literally ("Drużyna z trzema Zapisami na cztery miejsca" is explicitly
     * NOT yet complete, still short one invite). `relay_min_team_size` is
     * exposed alongside it as the separate "legal floor to even start"
     * number (Answer §1 — a team below it is still a legal, existing
     * Drużyna, just one this ticket does nothing further to gate), not
     * folded into `complete` itself. `complete` is null (unknown) rather
     * than true/false when the Dystans has no `relay_max_team_size`
     * configured at all — there is then no declared target size to compare
     * against, and silently treating "no limit configured" as "always
     * complete" would be misleading.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withCompleteness(array $row): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT relay_min_team_size AS minSize, relay_max_team_size AS maxSize FROM event_distances WHERE id = :id'
        );
        $stmt->execute(['id' => $row['distanceId']]);
        $distance = $stmt->fetch() ?: ['minSize' => null, 'maxSize' => null];

        $memberCount = $this->memberCount((string) $row['id']);
        $minSize = $distance['minSize'] !== null ? (int) $distance['minSize'] : null;
        $maxSize = $distance['maxSize'] !== null ? (int) $distance['maxSize'] : null;

        $row['memberCount'] = $memberCount;
        $row['minSize'] = $minSize;
        $row['maxSize'] = $maxSize;
        $row['complete'] = $maxSize === null ? null : $memberCount >= $maxSize;

        return $row;
    }
}
