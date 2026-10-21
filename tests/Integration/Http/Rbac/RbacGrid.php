<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http\Rbac;

/**
 * The RBAC expectation grid. One row per registered route ("<METHOD> <pattern>",
 * exactly as Slim's route collector reports it); the test enumerates the real routes
 * and fails for any route without a row (and any row without a route).
 *
 * Expectation tokens: int = exact status; PASS = authorization passed (status not
 * 401/403 - any validation/missing-data outcome is fine, the grid tests authorization,
 * not handler robustness); OK = 2xx exactly.
 *
 * Rule: if a row fails for a fixture reason, fix the fixture; change an expectation
 * only with a plain-English justification in `basis`.
 */
final class RbacGrid
{
    public const ACTORS = ['anon', 'participant', 'owner', 'foreignOrganizer', 'organizer', 'moderator', 'admin', 'superadmin', 'coManager', 'coManagerOrg', 'revoked'];

    /** The base policy tables below cover the first eight actors (up to `superadmin`). */
    private const BASE_ACTORS = ['anon', 'participant', 'owner', 'foreignOrganizer', 'organizer', 'moderator', 'admin', 'superadmin'];

    public const PASS = 'PASS';
    public const OK = 'OK';
    public const SKIP = 'SKIP';

    public const PUBLIC = 'PUBLIC';
    public const AUTH = 'AUTH';
    public const PARTICIPANT_ROLE = 'PARTICIPANT_ROLE';
    public const STAFF = 'STAFF';
    public const ADMIN = 'ADMIN';
    public const ROLE_EVENT_MANAGER = 'ROLE_EVENT_MANAGER';
    public const EVENT_MANAGER = 'EVENT_MANAGER';
    public const EVENT_OWNER_OR_ADMIN = 'EVENT_OWNER_OR_ADMIN';
    public const INVITEE_ONLY = 'INVITEE_ONLY';
    public const REG_OWNER_OR_MANAGER = 'REG_OWNER_OR_MANAGER';
    public const REG_MANAGER = 'REG_MANAGER';
    public const REG_MONEY = 'REG_MONEY';
    public const SELF_OR_STAFF = 'SELF_OR_STAFF';
    public const SELF_OR_ACCOUNT_MANAGER = 'SELF_OR_ACCOUNT_MANAGER';
    public const CARE_HOLDER = 'CARE_HOLDER';
    public const CARE_OR_STAFF = 'CARE_OR_STAFF';
    public const CARE_REVOKE = 'CARE_REVOKE';
    public const CARE_OR_MANAGER_ROLE = 'CARE_OR_MANAGER_ROLE';
    public const CAPTAIN_ONLY = 'CAPTAIN_ONLY';
    public const CAPTAIN_OR_MANAGER = 'CAPTAIN_OR_MANAGER';
    public const SELF_ONLY = 'SELF_ONLY';
    public const API_KEY = 'API_KEY';
    public const SIGNED_WEBHOOK = 'SIGNED_WEBHOOK';
    public const ENV_GATED = 'ENV_GATED';

    /**
     * Policy => expectation per actor, in ACTORS order:
     * anon, participant, owner, foreignOrganizer, organizer, moderator, admin, superadmin
     * (the Team actors coManager, coManagerOrg, revoked come from teamColumns()).
     *
     * @return array<string, list<int|string>>
     */
    public static function policies(): array
    {
        $P = self::PASS;

        return [
            self::PUBLIC => [$P, $P, $P, $P, $P, $P, $P, $P],
            self::AUTH => [401, $P, $P, $P, $P, $P, $P, $P],
            self::PARTICIPANT_ROLE => [401, $P, $P, 403, 403, 403, 403, 403],
            self::STAFF => [401, 403, 403, 403, 403, $P, $P, $P],
            self::ADMIN => [401, 403, 403, 403, 403, 403, $P, $P],
            self::ROLE_EVENT_MANAGER => [401, 403, 403, $P, $P, $P, $P, $P],
            self::EVENT_MANAGER => [401, 403, 403, 403, $P, $P, $P, $P],
            self::EVENT_OWNER_OR_ADMIN => [401, 403, 403, 403, $P, 403, $P, $P],
            // Accepting an invitation - only the Account whose address was invited (the `participant` actor).
            self::INVITEE_ONLY => [401, $P, 403, 403, 403, 403, 403, 403],
            self::REG_OWNER_OR_MANAGER => [401, 403, $P, 403, $P, $P, $P, $P],
            self::REG_MANAGER => [401, 403, 403, 403, $P, $P, $P, $P],
            self::REG_MONEY => [401, 403, 403, 403, $P, 403, $P, $P],
            self::SELF_OR_STAFF => [401, 403, $P, 403, 403, $P, $P, $P],
            self::SELF_OR_ACCOUNT_MANAGER => [401, 403, $P, 403, 403, 403, $P, $P],
            self::CARE_HOLDER => [401, 403, $P, 403, 403, 403, 403, 403],
            self::CARE_OR_STAFF => [401, 403, $P, 403, 403, $P, $P, $P],
            self::CARE_REVOKE => [401, 404, $P, 404, 404, 404, 404, 404],
            self::CARE_OR_MANAGER_ROLE => [401, 403, $P, 403, $P, $P, $P, $P],
            self::CAPTAIN_ONLY => [401, 403, $P, 403, 403, 403, 403, 403],
            self::CAPTAIN_OR_MANAGER => [401, 403, $P, 403, $P, $P, $P, $P],
            self::SELF_ONLY => [401, 404, self::OK, 404, 404, 404, 404, 404],
            self::API_KEY => [401, 401, 401, 401, 401, 401, 401, 401],
            self::SIGNED_WEBHOOK => [400, 400, 400, 400, 400, 400, 400, 400],
        ];
    }

    /**
     * Policy => [coManager, coManagerOrg, revoked]. coManager = participant-role active Co-manager of
     * `event`; coManagerOrg = the same with the organizer role; revoked = a participant whose membership was
     * revoked (must get exactly what a stranger gets). Owner-only and money policies deny Co-managers.
     *
     * @return array<string, list<int|string>>
     */
    public static function teamColumns(): array
    {
        $P = self::PASS;

        return [
            self::PUBLIC => [$P, $P, $P],
            self::AUTH => [$P, $P, $P],
            self::PARTICIPANT_ROLE => [$P, 403, $P],
            self::STAFF => [403, 403, 403],
            self::ADMIN => [403, 403, 403],
            self::ROLE_EVENT_MANAGER => [403, $P, 403],
            self::EVENT_MANAGER => [$P, $P, 403],
            self::EVENT_OWNER_OR_ADMIN => [403, 403, 403],
            self::INVITEE_ONLY => [403, 403, 403],
            self::REG_OWNER_OR_MANAGER => [$P, $P, 403],
            self::REG_MANAGER => [$P, $P, 403],
            self::REG_MONEY => [403, 403, 403],
            self::SELF_OR_STAFF => [403, 403, 403],
            self::SELF_OR_ACCOUNT_MANAGER => [403, 403, 403],
            self::CARE_HOLDER => [403, 403, 403],
            self::CARE_OR_STAFF => [403, 403, 403],
            self::CARE_REVOKE => [404, 404, 404],
            // An active Co-manager of an Event the Competitor is registered on may report, like its Owner.
            self::CARE_OR_MANAGER_ROLE => [$P, $P, 403],
            self::CAPTAIN_ONLY => [403, 403, 403],
            self::CAPTAIN_OR_MANAGER => [$P, $P, 403],
            self::SELF_ONLY => [404, 404, 404],
            self::API_KEY => [401, 401, 401],
            self::SIGNED_WEBHOOK => [400, 400, 400],
        ];
    }

    /** @return array<string, int|string> */
    public static function allActors(int|string $token): array
    {
        return array_fill_keys(self::ACTORS, $token);
    }

    /**
     * Expanded actor => token map for a row key.
     *
     * @return array<string, int|string>
     */
    public static function expectations(string $key): array
    {
        $row = self::rows()[$key];
        $policy = (string) $row['policy'];
        if ($policy === self::ENV_GATED) {
            return self::allActors(self::SKIP);
        }
        $table = self::policies()[$policy];
        $map = array_combine(self::BASE_ACTORS, $table)
            + array_combine(['coManager', 'coManagerOrg', 'revoked'], self::teamColumns()[$policy]);

        return array_replace($map, $row['override'] ?? []);
    }

    /**
     * Visibility probes (V5-V8).
     *
     * @return array<string, array{kind: string, path: string, actors: array<string, int|string>}>
     */
    public static function visibilityProbes(): array
    {
        $allList = array_fill_keys(self::ACTORS, 'list');

        return [
            'V1 list hides draft and private' => ['kind' => 'list', 'path' => '/api/v1/events?search={prefix}',
                'actors' => ['anon' => 'list', 'participant' => 'list', 'foreignOrganizer' => 'list']],
            'V2 draft event by id' => ['kind' => 'status', 'path' => '/api/v1/events/{eventDraft}', 'actors' => [
                'anon' => 404, 'participant' => 404, 'owner' => 404, 'foreignOrganizer' => 404, 'moderator' => 404,
                'organizer' => 200, 'admin' => 200, 'superadmin' => 200, 'coManager' => 200, 'coManagerOrg' => 200, 'revoked' => 404]],
            'V3 private event by id without code' => ['kind' => 'status', 'path' => '/api/v1/events/{eventPrivate}', 'actors' => [
                'anon' => 404, 'participant' => 404, 'foreignOrganizer' => 404,
                'organizer' => 200, 'moderator' => 200, 'admin' => 200, 'coManager' => 200, 'coManagerOrg' => 200, 'revoked' => 404]],
            'V4 private event by id with code' => ['kind' => 'status', 'path' => '/api/v1/events/{eventPrivate}?accessCode=rbac-code', 'actors' => [
                'anon' => 200, 'participant' => 200, 'foreignOrganizer' => 404]],
            'V5 includePrivate does not widen the list for the non-entitled' => ['kind' => 'list', 'path' => '/api/v1/events?includePrivate=1&search={prefix}',
                'actors' => ['anon' => 'list', 'participant' => 'list', 'owner' => 'list', 'foreignOrganizer' => 'list', 'revoked' => 'list']],
            'V6 includePrivate lists private for the owner and staff' => ['kind' => 'list+private', 'path' => '/api/v1/events?includePrivate=1&search={prefix}',
                'actors' => ['organizer' => 'list+private', 'moderator' => 'list+private', 'admin' => 'list+private', 'superadmin' => 'list+private', 'coManager' => 'list+private', 'coManagerOrg' => 'list+private']],
            'V7 featured hides private and deleted (H8)' => ['kind' => 'list', 'path' => '/api/v1/events/featured', 'actors' => $allList],
            'V8 live hides private and deleted (H8)' => ['kind' => 'list', 'path' => '/api/v1/events/live', 'actors' => $allList],
        ];
    }

    /**
     * @return array<string, array{policy: string, params?: array<string, string>, body?: array<string, mixed>,
     *                              override?: array<string, int|string>, optional?: bool, basis: string}>
     */
    public static function rows(): array
    {
        return [
        'GET /uploads/{path:.+}' => ['policy' => self::PUBLIC, 'params' => ['path' => 'rbac-missing.png'], 'optional' => true, 'basis' => 'public files'],
        'GET /api/v1/health' => ['policy' => self::PUBLIC, 'basis' => 'health probe is public'],
        'POST /api/v1/uploads/avatars' => ['policy' => self::AUTH, 'basis' => 'own avatar (auth.sub)'],
        'POST /api/v1/uploads/event-images' => ['policy' => self::ROLE_EVENT_MANAGER, 'override' => ['coManager' => self::PASS], 'basis' => 'upload precedes the Event; no ownership; also an active Co-manager of any role'],
        'POST /api/v1/uploads/event-documents' => ['policy' => self::ROLE_EVENT_MANAGER, 'override' => ['coManager' => self::PASS], 'basis' => 'idem; also an active Co-manager of any role'],
        'POST /api/v1/uploads/partner-logos' => ['policy' => self::ADMIN, 'basis' => 'partners are Admin-managed'],
        'GET /api/v1/uploads/{path:.+}' => ['policy' => self::PUBLIC, 'params' => ['path' => 'rbac-missing.png'], 'basis' => 'public files'],
        'POST /api/v1/auth/login' => ['policy' => self::PUBLIC, 'body' => ['email' => '@ownerEmail', 'password' => 'rbac-pass-1234'], 'override' => self::allActors(self::OK), 'basis' => 'a valid login is 200 for any caller'],
        'POST /api/v1/auth/register' => ['policy' => self::PUBLIC, 'basis' => 'sign-up, role forced to participant'],
        'POST /api/v1/auth/logout' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/auth/password/reset' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/auth/password/reset/confirm' => ['policy' => self::PUBLIC, 'body' => ['token' => 'rbac-bad', 'newPassword' => 'rbac-pass-9999'], 'basis' => 'public, authorised by the one-time token only'],
        'POST /api/v1/auth/email/verify' => ['policy' => self::PUBLIC, 'body' => ['token' => 'rbac-bad'], 'basis' => 'public, authorised by the one-time token only'],
        'POST /api/v1/auth/email/verification/resend' => ['policy' => self::AUTH, 'basis' => 'any signed-in session, self only'],
        'POST /api/v1/auth/email/change' => ['policy' => self::AUTH, 'basis' => 'any signed-in session, self only; the empty body is a 422'],
        'POST /api/v1/auth/email/change/confirm' => ['policy' => self::PUBLIC, 'body' => ['token' => 'rbac-bad'], 'basis' => 'public, authorised by the one-time token only'],
        'GET /api/v1/auth/me' => ['policy' => self::AUTH, 'basis' => 'any signed-in session (no extra per-row rule)'],
        'POST /api/v1/auth/password/change' => ['policy' => self::AUTH, 'basis' => 'self only (auth.sub)'],
        'GET /api/v1/users' => ['policy' => self::STAFF, 'basis' => 'account list: moderator can read'],
        'GET /api/v1/users/{id}' => ['policy' => self::SELF_OR_STAFF, 'params' => ['id' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'own account or staff'],
        'POST /api/v1/users' => ['policy' => self::ADMIN, 'basis' => 'Admin manages non-admin accounts'],
        'PATCH /api/v1/users/{id}' => ['policy' => self::SELF_OR_ACCOUNT_MANAGER, 'params' => ['id' => 'owner'], 'basis' => 'own account or account manager; moderator is read-only'],
        'DELETE /api/v1/users/{id}' => ['policy' => self::ADMIN, 'params' => ['id' => 'owner'], 'basis' => 'deleting an account is Admin-only'],
        'PATCH /api/v1/users/{id}/role' => ['policy' => self::ADMIN, 'params' => ['id' => 'owner'], 'basis' => 'promotion to Organizator is Admin-only'],
        'PATCH /api/v1/users/{id}/status' => ['policy' => self::STAFF, 'params' => ['id' => 'owner'], 'basis' => 'blocking an account: moderator allowed'],
        'GET /api/v1/participants' => ['policy' => self::STAFF, 'basis' => 'staff-only listing'],
        'GET /api/v1/organizers' => ['policy' => self::STAFF, 'basis' => 'staff-only listing'],
        'GET /api/v1/events' => ['policy' => self::PUBLIC, 'override' => self::allActors(self::OK), 'basis' => 'public calendar (visibility: probes V1, V5, V6)'],
        'GET /api/v1/events/featured' => ['policy' => self::PUBLIC, 'override' => self::allActors(self::OK), 'basis' => 'public feed (probe V7, H8)'],
        'GET /api/v1/events/live' => ['policy' => self::PUBLIC, 'override' => self::allActors(self::OK), 'basis' => 'public feed (probe V8, H8)'],
        'GET /api/v1/events/slug/{slug}' => ['policy' => self::PUBLIC, 'params' => ['slug' => 'eventSlug'], 'override' => self::allActors(self::OK), 'basis' => 'published public Event'],
        'GET /api/v1/events/{id}' => ['policy' => self::PUBLIC, 'params' => ['id' => 'event'], 'override' => self::allActors(self::OK), 'basis' => 'idem (draft/private: probes V2–V4)'],
        'GET /api/v1/events/{id}/embed/public' => ['policy' => self::PUBLIC, 'params' => ['id' => 'event'], 'basis' => 'public embed of a published Event'],
        'POST /api/v1/events' => ['policy' => self::ROLE_EVENT_MANAGER, 'basis' => 'creating an Event: Organizator/Moderator/Admin'],
        'POST /api/v1/events/external' => ['policy' => self::STAFF, 'basis' => 'staff-only external Event creation'],
        'PATCH /api/v1/events/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'Event Team + Moderator (Co-manager) + Admin; H5 in targeted test'],
        'DELETE /api/v1/events/{id}' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event'], 'basis' => 'H4'],
        'GET /api/v1/events/{id}/team' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event'], 'basis' => 'Team roster is Owner-level (reserved for the Owner); Co-manager and moderator denied'],
        'POST /api/v1/events/{id}/team/invitations' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event'], 'body' => ['email' => 'rbac-invitee@example.test'], 'basis' => 'inviting is Owner-level'],
        'POST /api/v1/events/{id}/team/{memberId}/resend' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event', 'memberId' => 'teamMemberRow'], 'basis' => 'Owner-level'],
        'DELETE /api/v1/events/{id}/team/{memberId}' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event', 'memberId' => 'teamMemberRow'], 'basis' => 'revoking is Owner-level'],
        'GET /api/v1/team-invitations/{token}' => ['policy' => self::PUBLIC, 'params' => ['token' => 'inviteToken'], 'override' => self::allActors(self::OK), 'basis' => 'public preview, the token is the credential'],
        'POST /api/v1/team-invitations/{token}/accept' => ['policy' => self::INVITEE_ONLY, 'params' => ['token' => 'inviteToken'], 'basis' => 'any signed-in Account, but only the invited address may accept (403 otherwise)'],
        'POST /api/v1/events/{id}/submit' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'POST /api/v1/events/{id}/approve' => ['policy' => self::STAFF, 'params' => ['id' => 'event'], 'basis' => 'moderation: moderator allowed'],
        'POST /api/v1/events/{id}/reject' => ['policy' => self::STAFF, 'params' => ['id' => 'event'], 'basis' => 'idem'],
        'POST /api/v1/events/{id}/cancel' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event'], 'basis' => 'cancelling an Event: owner or Admin'],
        'PATCH /api/v1/events/{id}/visibility' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'visibility is changed by the Event manager'],
        'PATCH /api/v1/events/{id}/is-test' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => '(published → 409 for managers = PASS)'],
        'GET /api/v1/organizers/{organizerId}/events' => ['policy' => self::EVENT_MANAGER, 'params' => ['organizerId' => 'organizer'], 'override' => ['coManager' => 403, 'coManagerOrg' => 403], 'basis' => 'own list or staff; a Co-manager reads only its own panel list (targeted test), never the Owner\'s'],
        'GET /api/v1/organizers/{organizerId}/event-limit' => ['policy' => self::EVENT_MANAGER, 'params' => ['organizerId' => 'organizer'], 'override' => ['coManager' => 403, 'coManagerOrg' => 403], 'basis' => 'idem; own limit or staff only, a Co-manager has no Event slots of its own'],
        'PATCH /api/v1/organizers/{organizerId}/event-limit' => ['policy' => self::ADMIN, 'params' => ['organizerId' => 'organizer'], 'basis' => 'packages are Admin-managed'],
        'GET /api/v1/registrations' => ['policy' => self::STAFF, 'basis' => 'staff-only listing'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/teams' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event', 'distanceId' => 'relay'], 'basis' => 'public team list for a relay Dystans'],
        'GET /api/v1/teams/{teamId}' => ['policy' => self::PUBLIC, 'params' => ['teamId' => 'team'], 'basis' => 'public team page'],
        'GET /api/v1/teams/{teamId}/invite' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['teamId' => 'team'], 'basis' => 'captain or Event manager'],
        'PATCH /api/v1/teams/{teamId}/special-category' => ['policy' => self::CAPTAIN_ONLY, 'params' => ['teamId' => 'team'], 'basis' => 'only the captain declares the special category'],
        'GET /api/v1/teams/{teamId}/members' => ['policy' => self::CAPTAIN_OR_MANAGER, 'params' => ['teamId' => 'team'], 'basis' => 'swap roster: captain (owner) or Event Team'],
        'POST /api/v1/teams/{teamId}/members/{registrationId}/swap' => ['policy' => self::CAPTAIN_OR_MANAGER, 'params' => ['teamId' => 'team', 'registrationId' => 'teamMember'], 'basis' => 'captain (owner) or Event Team; moderator = Co-manager'],
        'GET /api/v1/events/{eventId}/registrations' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'Zapis list: Event manager'],
        'GET /api/v1/events/{eventId}/start-list' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'override' => self::allActors(self::OK), 'basis' => 'public start-list DTO'],
        'GET /api/v1/events/{eventId}/bz/participants' => ['policy' => self::API_KEY, 'params' => ['eventId' => 'event'], 'basis' => 'BZ participants pull: API key'],
        'PATCH /api/v1/events/{eventId}/bz/participants/{registrationId}' => ['policy' => self::API_KEY, 'params' => ['eventId' => 'event', 'registrationId' => 'registration'], 'basis' => 'BZ participant sync: API key'],
        'GET /api/v1/events/{eventId}/form-fields/public' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => 'public form fields'],
        'GET /api/v1/events/{eventId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'POST /api/v1/events/{eventId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'PATCH /api/v1/events/{eventId}/form-fields/{fieldId}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'fieldId' => 'formField'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'DELETE /api/v1/events/{eventId}/form-fields/{fieldId}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'fieldId' => 'formField'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/events/{id}/form-field-aggregates' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/events/{id}/registration-stats' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/start-list-export' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'start-list export: Event manager'],
        'POST /api/v1/events/{eventId}/discount-code-batches' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'same gate as start-list-export'],
        'GET /api/v1/events/{eventId}/discount-code-batches' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'same gate as start-list-export'],
        'GET /api/v1/events/{eventId}/discount-code-batches/{batchId}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'batchId' => 'discountBatch'], 'basis' => 'same gate as start-list-export'],
        'GET /api/v1/events/{eventId}/discount-code-batches/{batchId}/export' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'batchId' => 'discountBatch'], 'basis' => 'same gate as start-list-export'],
        'PUT /api/v1/events/{eventId}/form-fields/reorder' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'per-Dystans form: event manager (owner, moderator, admin)'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'per-Dystans form: event manager (owner, moderator, admin)'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/form-fields/{fieldId}/override' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist', 'fieldId' => 'formField'], 'basis' => 'per-Dystans form: event manager (owner, moderator, admin)'],
        'DELETE /api/v1/events/{eventId}/distances/{distanceId}/form-fields/{fieldId}/override' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist', 'fieldId' => 'formField'], 'basis' => 'per-Dystans form: event manager (owner, moderator, admin)'],
        'PUT /api/v1/registrations/{registrationId}/form-field-answers/{fieldId}' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['registrationId' => 'registration', 'fieldId' => 'formField'], 'basis' => 'owner of the Zapis or manager'],
        'GET /api/v1/registrations/{registrationId}/form-field-answers' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['registrationId' => 'registration'], 'basis' => 'owner of the Zapis or manager'],
        'GET /api/v1/users/{userId}/registrations' => ['policy' => self::SELF_OR_STAFF, 'params' => ['userId' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'own list or staff'],
        'POST /api/v1/registrations' => ['policy' => self::AUTH, 'basis' => 'Opieka; H11 in targeted test'],
        'POST /api/v1/registrations/quote' => ['policy' => self::AUTH, 'basis' => 'same gate as POST /registrations (Opieka, event visibility); writes nothing'],
        'PATCH /api/v1/registrations/{id}' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'owner of the Zapis or manager'],
        'POST /api/v1/events/{eventId}/bib-numbers/assign' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager assigns start numbers from the distance ranges'],
        'POST /api/v1/registrations/{id}/cancel' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'owner of the Zapis or manager'],
        'GET /api/v1/registrations/{id}/changes' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'history of one Zapis, owner + manager'],
        'POST /api/v1/registrations/{id}/distance-change/quote' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'what a change of Dystans would cost, writes nothing'],
        'POST /api/v1/registrations/{id}/distance-change' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'owner inside the self-service window, manager always'],
        'POST /api/v1/registrations/{id}/transfer' => ['policy' => self::REG_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'transfer of a Zapis to another Zawodnik, the Event manager only'],
        'PATCH /api/v1/registrations/{id}/payment-details' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6'],
        'POST /api/v1/registrations/{id}/refund-request' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'the Zawodnik requests a refund'],
        'POST /api/v1/registrations/{id}/refund-approve' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6'],
        'POST /api/v1/registrations/{id}/refund-complete' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6'],
        'POST /api/v1/registrations/{id}/retry-payment' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'restarts the participant\'s own session'],
        'POST /api/v1/registrations/{id}/mark-paid' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6'],
        'PATCH /api/v1/registrations/{id}/form-snapshot' => ['policy' => self::REG_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'form snapshot correction: Event manager'],
        'POST /api/v1/webhooks/tpay' => ['policy' => self::SIGNED_WEBHOOK, 'basis' => 'signed webhook'],
        'POST /api/v1/webhooks/paynow' => ['policy' => self::SIGNED_WEBHOOK, 'basis' => 'signed webhook'],
        'GET /api/v1/virtual-results' => ['policy' => self::AUTH, 'basis' => 'non-managers forced to their own rows (:2203)'],
        'POST /api/v1/virtual-results' => ['policy' => self::PARTICIPANT_ROLE, 'basis' => 'PARTICIPANT_ROLE (no extra per-row rule)'],
        'PATCH /api/v1/virtual-results/{id}/review' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'virtualSubmission'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/results' => ['policy' => self::PUBLIC, 'basis' => 'results are public'],
        'GET /api/v1/events/{eventId}/results' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => 'results are public'],
        'GET /api/v1/users/{userId}/results' => ['policy' => self::PUBLIC, 'params' => ['userId' => 'owner'], 'basis' => 'athlete profile'],
        'POST /api/v1/results' => ['policy' => self::EVENT_MANAGER, 'body' => ['eventId' => '@event', 'distanceId' => '@dist'], 'basis' => '$canUploadResults reads the body\'s Event'],
        'PATCH /api/v1/results/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'result'], 'basis' => 'H7 in targeted test'],
        'GET /api/v1/results/{id}/corrections' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'result'], 'basis' => 'same right as editing the result'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/results/publish-official' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'DELETE /api/v1/results/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'result'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/users/{userId}/personal-records' => ['policy' => self::PUBLIC, 'params' => ['userId' => 'owner'], 'basis' => 'public records'],
        'GET /api/v1/competitors/{id}/records' => ['policy' => self::CARE_OR_STAFF, 'params' => ['id' => 'child'], 'basis' => 'care holder or staff'],
        'GET /api/v1/events/{eventId}/team-results' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => 'results are public'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/team-results' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event', 'distanceId' => 'relay'], 'basis' => 'results are public'],
        'GET /api/v1/events/{eventId}/result-documents' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => 'results are public'],
        'POST /api/v1/events/{eventId}/result-documents' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/result-imports' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/result-imports/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importBatch'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'PATCH /api/v1/result-imports/{id}/mapping' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importBatch'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'PATCH /api/v1/result-import-rows/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importRow'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'POST /api/v1/result-import-rows/{id}/create-registration' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importRow'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'POST /api/v1/result-imports/{id}/commit' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importBatch'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        'GET /api/v1/series' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/series/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/series/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/series/{id}/ranking' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/series' => ['policy' => self::STAFF, 'basis' => 'staff-managed (not covered by the permission matrix)'],
        'PATCH /api/v1/series/{id}' => ['policy' => self::STAFF, 'basis' => 'staff-managed'],
        'POST /api/v1/series/{id}/archive' => ['policy' => self::STAFF, 'basis' => 'staff-managed'],
        'GET /api/v1/shop/categories' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/shop/categories' => ['policy' => self::ADMIN, 'basis' => 'shop categories are Admin-managed'],
        'PATCH /api/v1/shop/categories/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'DELETE /api/v1/shop/categories/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'GET /api/v1/shop/delivery-methods' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/shop/payment-methods' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/calendar/settings' => ['policy' => self::ADMIN, 'basis' => 'Google Calendar settings = Admin'],
        'PATCH /api/v1/calendar/settings' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'POST /api/v1/calendar/events' => ['policy' => self::ADMIN, 'basis' => 'manual send to calendar = Admin'],
        'GET /api/v1/insurance/settings' => ['policy' => self::PUBLIC, 'basis' => 'read by the registration form'],
        'PATCH /api/v1/insurance/settings' => ['policy' => self::ADMIN, 'basis' => 'global insurance settings are Admin-managed'],
        'GET /api/v1/insurance/variants' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/insurance/variants' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'PATCH /api/v1/insurance/variants/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'DELETE /api/v1/insurance/variants/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'GET /api/v1/shop/orders' => ['policy' => self::STAFF, 'basis' => 'staff full projection (Moderator/Admin); participant own orders = GET /me/orders'],
        'GET /api/v1/shop/orders/{id}' => ['policy' => self::STAFF, 'basis' => 'idem; own single order = GET /me/orders/{id}'],
        'POST /api/v1/shop/orders' => ['policy' => self::AUTH, 'basis' => 'buyer = auth.sub'],
        'GET /api/v1/me/orders' => ['policy' => self::AUTH, 'override' => ['participant' => self::OK, 'owner' => self::OK, 'foreignOrganizer' => self::OK, 'organizer' => self::OK, 'moderator' => self::OK, 'admin' => self::OK, 'superadmin' => self::OK, 'coManager' => self::OK, 'coManagerOrg' => self::OK, 'revoked' => self::OK], 'basis' => 'own orders only; identity = auth.sub'],
        'GET /api/v1/me/orders/{id}' => ['policy' => self::SELF_ONLY, 'params' => ['id' => 'order'], 'basis' => 'own orders only; foreign id = 404, not 403 (no enumeration)'],
        'PATCH /api/v1/shop/orders/{id}/status' => ['policy' => self::STAFF, 'params' => ['id' => 'order'], 'body' => ['status' => 'shipped'], 'basis' => 'Moderator may only change the status'],
        'PATCH /api/v1/shop/orders/{id}' => ['policy' => self::ADMIN, 'basis' => 'H1'],
        'GET /api/v1/shop/products' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/shop/products/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/shop/products/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/shop/products' => ['policy' => self::ADMIN, 'basis' => 'shop products are Admin-managed'],
        'PATCH /api/v1/shop/products/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'DELETE /api/v1/shop/products/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'GET /api/v1/challenges' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/challenges/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/challenges/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/challenges' => ['policy' => self::ADMIN, 'basis' => 'H2'],
        'PATCH /api/v1/challenges/{id}' => ['policy' => self::ADMIN, 'basis' => 'H2'],
        'GET /api/v1/challenges/{id}/condition-tree' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'PUT /api/v1/challenges/{id}/condition-tree' => ['policy' => self::ADMIN, 'basis' => 'H2'],
        'GET /api/v1/challenge-points-settings' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'PATCH /api/v1/challenge-points-settings' => ['policy' => self::ADMIN, 'basis' => 'points settings are Admin-managed'],
        'POST /api/v1/challenges/{id}/condition-tree/point-score/{userId}' => ['policy' => self::AUTH, 'params' => ['userId' => 'owner'], 'basis' => 'any signed-in session'],
        'GET /api/v1/challenges/{id}/ranking' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/challenges/{id}/archive' => ['policy' => self::ADMIN, 'basis' => 'H2'],
        'GET /api/v1/challenge-participations' => ['policy' => self::AUTH, 'basis' => 'any signed-in session'],
        'POST /api/v1/challenges/{id}/join' => ['policy' => self::AUTH, 'basis' => 'self (auth.sub)'],
        'POST /api/v1/challenge-entries' => ['policy' => self::AUTH, 'basis' => 'H3 in targeted test'],
        'POST /api/v1/challenges/{id}/claim-reward' => ['policy' => self::AUTH, 'basis' => 'self (auth.sub)'],
        'PATCH /api/v1/challenge-entries/{id}/review' => ['policy' => self::STAFF, 'basis' => 'entry review: moderator allowed'],
        'GET /api/v1/info-pages' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/info-pages/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/info-pages' => ['policy' => self::ADMIN, 'basis' => 'CMS pages are Admin-managed'],
        'PATCH /api/v1/info-pages/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'DELETE /api/v1/info-pages/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'POST /api/v1/info-pages/reorder' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'GET /api/v1/partners' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/partners/featured' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/partners/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'POST /api/v1/partners' => ['policy' => self::ADMIN, 'basis' => 'partners are Admin-managed'],
        'PATCH /api/v1/partners/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'DELETE /api/v1/partners/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem'],
        'GET /api/v1/event-backgrounds' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/event-backgrounds/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule)'],
        'GET /api/v1/event-backgrounds/{id}/usages' => ['policy' => self::STAFF, 'basis' => 'staff-managed'],
        'POST /api/v1/uploads/event-backgrounds' => ['policy' => self::STAFF, 'basis' => 'staff-managed'],
        'POST /api/v1/event-backgrounds' => ['policy' => self::STAFF, 'basis' => 'staff-managed'],
        'PATCH /api/v1/event-backgrounds/{id}' => ['policy' => self::STAFF, 'basis' => 'staff-managed'],
        'DELETE /api/v1/event-backgrounds/{id}' => ['policy' => self::STAFF, 'basis' => 'staff-managed'],
        'GET /api/v1/admin/activity-logs' => ['policy' => self::ADMIN, 'basis' => 'global read is Admin-only'],
        'GET /api/v1/events/{id}/activity-logs' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'Event slice: Owner/Moderator/Admin'],
        'GET /api/v1/admin/security-settings' => ['policy' => self::ADMIN, 'basis' => 'admin-only (no extra per-row rule)'],
        'PATCH /api/v1/admin/security-settings' => ['policy' => self::ADMIN, 'basis' => 'admin-only (no extra per-row rule)'],
        'GET /api/v1/admin/online-sessions' => ['policy' => self::ADMIN, 'basis' => 'H10'],
        'GET /api/v1/admin/sales-stats' => ['policy' => self::ADMIN, 'basis' => 'admin-only (same as platform-stats)'],
        'GET /api/v1/admin/platform-stats' => ['policy' => self::ADMIN, 'basis' => 'admin-only (no extra per-row rule)'],
        'GET /api/v1/admin/settlements' => ['policy' => self::ADMIN, 'basis' => 'admin-only: route A settlement report, organizer gets 403'],
        'GET /api/v1/admin/settlements/export' => ['policy' => self::ADMIN, 'basis' => 'admin-only: settlement report export'],
        'GET /api/v1/users/{userId}/tickets' => ['policy' => self::SELF_OR_STAFF, 'params' => ['userId' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'own list or staff'],
        'GET /api/v1/users/{userId}/certificates' => ['policy' => self::SELF_OR_STAFF, 'params' => ['userId' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'own list or staff'],
        'POST /api/v1/dev/seed-event' => ['policy' => self::ENV_GATED, 'basis' => 'environment-gated'],
        'GET /api/v1/competitors/duplicates' => ['policy' => self::AUTH, 'basis' => 'H9 in targeted test (shape)'],
        'GET /api/v1/care' => ['policy' => self::AUTH, 'basis' => 'own data (auth.sub)'],
        'POST /api/v1/care' => ['policy' => self::PARTICIPANT_ROLE, 'basis' => 'participant-role accounts only'],
        'DELETE /api/v1/care/{id}' => ['policy' => self::CARE_REVOKE, 'params' => ['id' => 'care'], 'basis' => 'care holder may revoke'],
        'POST /api/v1/competitors/{id}/rotate-identity-code' => ['policy' => self::CARE_HOLDER, 'params' => ['id' => 'child'], 'basis' => 'care holder only'],
        'POST /api/v1/competitors/{id}/anonymization' => ['policy' => self::CARE_HOLDER, 'params' => ['id' => 'child'], 'basis' => 'care holder only'],
        'POST /api/v1/competitors/{id}/duplicate-reports' => ['policy' => self::CARE_OR_MANAGER_ROLE, 'params' => ['id' => 'child'], 'basis' => 'caretaker, organizer of a Zawodnik registered on its Event, or staff'],
        'PATCH /api/v1/admin/competitors/duplicate-reports/{id}' => ['policy' => self::STAFF, 'params' => ['id' => 'dupReport'], 'basis' => 'rejecting a report: Moderator/Admin'],
        'GET /api/v1/admin/competitors/duplicate-reports' => ['policy' => self::STAFF, 'basis' => 'report queue: Moderator/Admin, like merging'],
        'GET /api/v1/admin/competitors/search' => ['policy' => self::STAFF, 'basis' => 'merge search: Moderator allowed (a 500 still counts as PASS)'],
        'GET /api/v1/admin/competitors/{idA}/merge-comparison/{idB}' => ['policy' => self::STAFF, 'params' => ['idA' => 'child', 'idB' => 'competitorB'], 'basis' => 'idem'],
        'POST /api/v1/admin/competitors/merge' => ['policy' => self::STAFF, 'basis' => 'idem (empty body → 422)'],
        'GET /api/v1/admin/mail-templates' => ['policy' => self::ADMIN, 'basis' => 'global templates are Admin-managed'],
        'PUT /api/v1/admin/mail-templates/{mailType}' => ['policy' => self::ADMIN, 'params' => ['mailType' => 'mailType'], 'basis' => 'idem'],
        'GET /api/v1/events/{eventId}/mail-templates' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'Event templates: Event Team/Moderator/Admin'],
        'PUT /api/v1/events/{eventId}/mail-templates/{mailType}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'mailType' => 'mailType'], 'basis' => 'idem'],
        'DELETE /api/v1/events/{eventId}/mail-templates/{mailType}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'mailType' => 'mailType'], 'basis' => 'idem'],
        'POST /api/v1/admin/mail-templates/{mailType}/preview' => ['policy' => self::ADMIN, 'params' => ['mailType' => 'mailType'], 'basis' => 'preview of a global template = Admin (like PUT)'],
        'POST /api/v1/events/{eventId}/mail-templates/{mailType}/preview' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'mailType' => 'mailType'], 'basis' => 'preview of an Event template = Event Team (like PUT)'],
        'GET /api/v1/admin/mail-log' => ['policy' => self::ADMIN, 'basis' => 'global mail log = Admin'],
        'GET /api/v1/events/{eventId}/mail-log' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'Event mail log = Event Team'],
        'GET /api/v1/events/{eventId}/mail-send-progress' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule)'],
        ];
    }
}
