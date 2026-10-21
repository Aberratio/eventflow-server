<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Teams;

use ZmierzymyCzas\Domain\Repository;

/**
 * Team lifecycle ("Cykl życia Drużyny": an incomplete roster is legal, the limit counts Zapisy)
 * — the real entity behind what used to be an
 * unconstrained `registrations.team_id`/`team_name` free-text pair.
 *
 * Deliberately thin: this class only owns team creation/lookup and the
 * derived "completeness" read. It does NOT own:
 *  - capacity/limit counting — that is `event_distances.registered_count`,
 *    maintained entirely by RegistrationRepository and untouched by
 *    this repository; a team's completeness is a separate, purely informational
 *    number, never fed back into the Dystans limit;
 *  - joining/creating a Zapis into a team — RegistrationRepository::create()
 *    is the only writer of `registrations.team_id`, and is the one that
 *    calls into this class (create()/find()) while already holding the
 *    Dystans row lock, so every team-size check below is implicitly
 *    serialized by that same lock — no separate row lock is taken here.
 *  - The Kategoria specjalna — `special_category` exists on the table and
 *    is carried through find()/forDistance() so it is never lost, but
 *    nothing here ever sets it to anything but null.
 *
 *    UPDATE: create() now accepts an optional
 *    specialCategory, validated against the Dystans's configured
 *    event_distances.special_categories list (the SAME list the
 *    individual-registration "Kategorie specjalne" checkboxes read — no
 *    separate "team categories" concept invented here). updateSpecialCategory()
 *    lets the captain (created_by_user_id) change it later, up until the
 *    event's start date. Both paths only ever set this column to a value the
 *    caller explicitly asked for — nothing here recomputes or corrects it
 *    from memberCount()/roster composition, by design: a stale declaration is an accepted cost.
 *
 * A team's id is PUBLIC (forDistance() lists it), so it
 * is not an invitation. Joining an existing team requires the team's random
 * `invite_token` (inviteToken()/inviteTokenMatches()) or the captain acting
 * (isCaptain()); the token is deliberately absent from COLUMNS so no public
 * read can carry it.
 *
 * "Completeness" ("stan czysto pochodny, wyliczany z liczby
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
     * constant is a bigger refactor than this change warrants.
     */
    private const NOT_COUNTED_STATUSES = ['cancelled', 'removed', 'expired'];

    /** 24 random bytes -> 48 lowercase hex chars (192 bits); fits invite_token VARCHAR(64). */
    private const INVITE_TOKEN_BYTES = 24;

    private const COLUMNS = 'id, event_id AS eventId, distance_id AS distanceId, name, '
        . 'created_by_user_id AS createdByUserId, special_category AS specialCategory, created_at AS createdAt';

    /**
     * @param array<string, mixed> $data eventId, distanceId, name, createdByUserId (optional),
     *                                    specialCategory (optional — the caller
     *                                    (RegistrationRepository::resolveTeamForRegistration())
     *                                    is responsible for validating it against
     *                                    specialCategoriesForDistance() BEFORE calling this,
     *                                    exactly as it already validates startType/team size
     *                                    there — this method itself does not re-validate.
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
            'special_category' => $data['specialCategory'] ?? null,
            'invite_token' => self::newInviteToken(),
            'created_at' => date(DATE_ATOM),
        ]);

        return $this->find($id);
    }

    /**
     * The invitation token is a random secret, NOT the team id (ids are public via
     * forDistance()). It is never part of COLUMNS, so find()/forDistance() cannot leak it.
     */
    public static function newInviteToken(): string
    {
        return bin2hex(random_bytes(self::INVITE_TOKEN_BYTES));
    }

    /** The captain is teams.created_by_user_id (see updateSpecialCategory()). Empty ids never match. */
    public static function isCaptain(array $team, string $userId): bool
    {
        return $userId !== '' && (string) ($team['createdByUserId'] ?? '') === $userId;
    }

    /**
     * The team's invitation token, minted on first use for teams created before migration 062.
     * Null when the team does not exist. Race-safe: the conditional UPDATE lets exactly one minter
     * win, and every caller then reads the winner's value.
     */
    public function inviteToken(string $teamId): ?string
    {
        $this->pdo->prepare('UPDATE teams SET invite_token = :token WHERE id = :id AND invite_token IS NULL')
            ->execute(['token' => self::newInviteToken(), 'id' => $teamId]);
        $stmt = $this->pdo->prepare('SELECT invite_token FROM teams WHERE id = :id');
        $stmt->execute(['id' => $teamId]);
        $token = $stmt->fetchColumn();

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** Constant-time check of a presented token. A team without a token never matches. */
    public function inviteTokenMatches(string $teamId, string $presented): bool
    {
        if ($presented === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT invite_token FROM teams WHERE id = :id');
        $stmt->execute(['id' => $teamId]);
        $stored = $stmt->fetchColumn();

        return is_string($stored) && $stored !== '' && hash_equals($stored, $presented);
    }

    /**
     * Lets the captain change a previously
     * declared Kategoria specjalna. Editability window judgment call (the
     * spec only fixes a deadline for the member-swap case —
     * it says nothing about this field): open until the Event's own start
     * date, then locked. Rationale: the whole argument for making
     * this manual in the first place is "predictability for the organizer
     * arranging results" — the same predictability concern that justifies
     * cutting off changes once the event is actually under way, same as any
     * other pre-race declaration. This is a deliberately simple choice, not
     * a new configurable deadline parameter like relay_team_completion
     * deadline — nothing asks for one here.
     *
     * Permission model mirrors resolveTeamForRegistration()'s existing
     * "captain" concept ("kapitan
     * (uprawnienie ograniczone do Zapisów swojej Drużyny ... nie pełna
     * Opieka)") — the only thing recorded anywhere today for "who is the
     * captain" is teams.created_by_user_id (set once, at create()), so that
     * is the check.
     *
     * @return array<string, mixed> the updated team
     */
    public function updateSpecialCategory(string $teamId, string $requestingUserId, ?string $specialCategory): array
    {
        $team = $this->find($teamId);
        if ($team === null) {
            throw new \RuntimeException('team_not_found');
        }
        if (!self::isCaptain($team, $requestingUserId)) {
            throw new \RuntimeException('team_not_captain');
        }
        if ($this->eventHasStarted((string) $team['eventId'])) {
            throw new \RuntimeException('team_special_category_locked');
        }

        $value = $specialCategory !== null && trim($specialCategory) !== '' ? trim($specialCategory) : null;
        self::validateSpecialCategory($value, $this->specialCategoriesForDistance((string) $team['distanceId']));

        $this->pdo->prepare('UPDATE teams SET special_category = :cat WHERE id = :id')
            ->execute(['cat' => $value, 'id' => $teamId]);

        return $this->find($teamId);
    }

    /**
     * True once the Event's own start date has passed (DATE granularity,
     * matching EventRepository::lazilyFinishPastEvents()'s existing
     * `CURDATE()` cutoff convention rather than inventing a new one) — the
     * editability-window boundary for updateSpecialCategory() above.
     */
    private function eventHasStarted(string $eventId): bool
    {
        $stmt = $this->pdo->prepare('SELECT (date <= CURDATE()) AS started FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $started = $stmt->fetchColumn();
        return $started !== false && (bool) $started;
    }

    /**
     * The organizer-configured special-category list for one Dystans — the
     * SAME `event_distances.special_categories` JSON column the
     * individual-registration form already reads
     * (EventRepository::DISTANCE_JSON), reused unchanged rather than
     * inventing a parallel "team categories" list ("analogicznie" to the individual
     * registration form).
     *
     * @return array<int, array<string, mixed>>
     */
    public function specialCategoriesForDistance(string $distanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT special_categories FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $distanceId]);
        $raw = $stmt->fetchColumn();
        return self::decodeSpecialCategories($raw !== false ? $raw : null);
    }

    /** @return array<int, array<string, mixed>> */
    public static function decodeSpecialCategories(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * A team's Kategoria specjalna is optional (null/empty is always legal —
     * declaring one is the captain's choice, not a requirement added here). When a value IS given, it must match one of the Dystans's
     * configured special categories by name — exactly the set the client's
     * own dropdown is built from, so a mismatch can only mean a stale/
     * tampered request, matching the "reject unknown category" ask.
     *
     * @param array<int, array<string, mixed>> $configured
     */
    public static function validateSpecialCategory(?string $value, array $configured): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $names = array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $configured);
        if (!in_array($value, $names, true)) {
            throw new \RuntimeException('team_special_category_invalid');
        }
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
     * "which teams can I join" read (the client's "join an
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
     * relay_max_team_size` — matching the documented worked example
     * literally ("Drużyna z trzema Zapisami na cztery miejsca" is explicitly
     * NOT yet complete, still short one invite). `relay_min_team_size` is
     * exposed alongside it as the separate "legal floor to even start"
     * number (a team below it is still a legal, existing
     * Drużyna, just one nothing further gates), not
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
