<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http\Rbac;

/**
 * PP.4: the RBAC expectation grid. One row per registered route ("<METHOD> <pattern>",
 * exactly as Slim's route collector reports it); the test enumerates the real routes
 * and fails for any route without a row (and any row without a route).
 *
 * Expectation tokens: int = exact status; PASS = authorization passed (status not
 * 401/403 - any validation/missing-data outcome is fine, the grid tests authorization,
 * not handler robustness); OK = 2xx exactly.
 *
 * Rule: if a row fails for a fixture reason, fix the fixture; change an expectation
 * only with the macierz-uprawnien citation in `basis`.
 */
final class RbacGrid
{
    public const ACTORS = ['anon', 'participant', 'owner', 'foreignOrganizer', 'organizer', 'moderator', 'admin', 'superadmin', 'coManager', 'coManagerOrg', 'revoked'];

    /** R-01: the base policy tables below cover the first eight actors (up to `superadmin`). */
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
     * (the R-01 Team actors coManager, coManagerOrg, revoked come from teamColumns()).
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
     * R-01: policy => [coManager, coManagerOrg, revoked]. coManager = participant-role active Co-manager of
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
            self::REG_OWNER_OR_MANAGER => [$P, $P, 403],
            self::REG_MANAGER => [$P, $P, 403],
            self::REG_MONEY => [403, 403, 403],
            self::SELF_OR_STAFF => [403, 403, 403],
            self::SELF_OR_ACCOUNT_MANAGER => [403, 403, 403],
            self::CARE_HOLDER => [403, 403, 403],
            self::CARE_OR_STAFF => [403, 403, 403],
            self::CARE_REVOKE => [404, 404, 404],
            // R-08 + R-01: an active Co-manager of an Event the Competitor is registered on may report, like its Owner.
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
     * Visibility probes (PP.55 closed the PP.4 tripwires T1-T3: V5-V8).
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
        'GET /uploads/{path:.+}' => ['policy' => self::PUBLIC, 'params' => ['path' => 'rbac-missing.png'], 'optional' => true, 'basis' => 'public files (plan line 446)'],
        'GET /api/v1/health' => ['policy' => self::PUBLIC, 'basis' => 'PP.20 probe (plan line 462)'],
        'POST /api/v1/uploads/avatars' => ['policy' => self::AUTH, 'basis' => 'own avatar (auth.sub) (plan line 479)'],
        'POST /api/v1/uploads/event-images' => ['policy' => self::ROLE_EVENT_MANAGER, 'override' => ['coManager' => self::PASS], 'basis' => 'upload precedes the Event; no ownership (plan line 510); R-01: also an active Co-manager of any role'],
        'POST /api/v1/uploads/event-documents' => ['policy' => self::ROLE_EVENT_MANAGER, 'override' => ['coManager' => self::PASS], 'basis' => 'idem (plan line 535); R-01: also an active Co-manager of any role'],
        'POST /api/v1/uploads/partner-logos' => ['policy' => self::ADMIN, 'basis' => 'macierz: Partnerzy = Admin (plan line 559)'],
        'GET /api/v1/uploads/{path:.+}' => ['policy' => self::PUBLIC, 'params' => ['path' => 'rbac-missing.png'], 'basis' => 'public files (plan line 584)'],
        'POST /api/v1/auth/login' => ['policy' => self::PUBLIC, 'body' => ['email' => '@ownerEmail', 'password' => 'rbac-pass-1234'], 'override' => self::allActors(self::OK), 'basis' => 'a valid login is 200 for any caller (plan line 597)'],
        'POST /api/v1/auth/register' => ['policy' => self::PUBLIC, 'basis' => 'sign-up, role forced to participant (PP.1) (plan line 608)'],
        'POST /api/v1/auth/logout' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 640)'],
        'POST /api/v1/auth/password/reset' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 641)'],
        'POST /api/v1/auth/password/reset/confirm' => ['policy' => self::PUBLIC, 'body' => ['token' => 'rbac-bad', 'newPassword' => 'rbac-pass-9999'], 'basis' => 'PP.7: public, authorised by the one-time token only (plan 3.9)'],
        'POST /api/v1/auth/email/verify' => ['policy' => self::PUBLIC, 'body' => ['token' => 'rbac-bad'], 'basis' => 'PP.7: public, authorised by the one-time token only (plan 3.9)'],
        'POST /api/v1/auth/email/verification/resend' => ['policy' => self::AUTH, 'basis' => 'PP.7: any signed-in session, self only (plan 3.9)'],
        'POST /api/v1/auth/email/change' => ['policy' => self::AUTH, 'basis' => 'PP.7: any signed-in session, self only; the empty body is a 422 (plan 3.9)'],
        'POST /api/v1/auth/email/change/confirm' => ['policy' => self::PUBLIC, 'body' => ['token' => 'rbac-bad'], 'basis' => 'PP.7: public, authorised by the one-time token only (plan 3.9)'],
        'GET /api/v1/auth/me' => ['policy' => self::AUTH, 'basis' => 'any signed-in session (no extra per-row rule) (plan line 658)'],
        'POST /api/v1/auth/password/change' => ['policy' => self::AUTH, 'basis' => 'self only (auth.sub) (plan line 666)'],
        'GET /api/v1/users' => ['policy' => self::STAFF, 'basis' => 'macierz: lista Kont — Moderator odczyt (plan line 696)'],
        'GET /api/v1/users/{id}' => ['policy' => self::SELF_OR_STAFF, 'params' => ['id' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'PP.1 (plan line 703)'],
        'POST /api/v1/users' => ['policy' => self::ADMIN, 'basis' => 'macierz §1: Admin manages non-admin accounts (plan line 710)'],
        'PATCH /api/v1/users/{id}' => ['policy' => self::SELF_OR_ACCOUNT_MANAGER, 'params' => ['id' => 'owner'], 'basis' => 'PP.1; macierz §3 moderator read-only (plan line 729)'],
        'DELETE /api/v1/users/{id}' => ['policy' => self::ADMIN, 'params' => ['id' => 'owner'], 'basis' => 'macierz: usuwanie konta = Admin (plan line 783)'],
        'PATCH /api/v1/users/{id}/role' => ['policy' => self::ADMIN, 'params' => ['id' => 'owner'], 'basis' => 'macierz: promocja do Organizatora = Admin (plan line 792)'],
        'PATCH /api/v1/users/{id}/status' => ['policy' => self::STAFF, 'params' => ['id' => 'owner'], 'basis' => 'macierz §5.11: blokowanie — Moderator ✔ (plan line 802)'],
        'GET /api/v1/participants' => ['policy' => self::STAFF, 'basis' => 'PP.1 (plan line 828)'],
        'GET /api/v1/organizers' => ['policy' => self::STAFF, 'basis' => 'PP.1 (plan line 832)'],
        'GET /api/v1/events' => ['policy' => self::PUBLIC, 'override' => self::allActors(self::OK), 'basis' => 'public calendar (visibility: probes V1, V5, V6) (plan line 837)'],
        'GET /api/v1/events/featured' => ['policy' => self::PUBLIC, 'override' => self::allActors(self::OK), 'basis' => 'public feed (probe V7, PP.55 H8) (plan line 856)'],
        'GET /api/v1/events/live' => ['policy' => self::PUBLIC, 'override' => self::allActors(self::OK), 'basis' => 'public feed (probe V8, PP.55 H8) (plan line 857)'],
        'GET /api/v1/events/slug/{slug}' => ['policy' => self::PUBLIC, 'params' => ['slug' => 'eventSlug'], 'override' => self::allActors(self::OK), 'basis' => 'published public Event (plan line 858)'],
        'GET /api/v1/events/{id}' => ['policy' => self::PUBLIC, 'params' => ['id' => 'event'], 'override' => self::allActors(self::OK), 'basis' => 'idem (draft/private: probes V2–V4) (plan line 867)'],
        'GET /api/v1/events/{id}/embed/public' => ['policy' => self::PUBLIC, 'params' => ['id' => 'event'], 'basis' => 'E8.11 (NEW-C note) (plan line 884)'],
        'POST /api/v1/events' => ['policy' => self::ROLE_EVENT_MANAGER, 'basis' => 'macierz: Tworzenie Wydarzenia — Organizator/Moderator/Admin (plan line 894)'],
        'POST /api/v1/events/external' => ['policy' => self::STAFF, 'basis' => 'E6.4 (§3.6) (plan line 932)'],
        'PATCH /api/v1/events/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'Zespół + Moderator (Współzarządzający) + Admin; H5 in targeted test (plan line 948)'],
        'DELETE /api/v1/events/{id}' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event'], 'basis' => 'H4 (plan line 1049)'],
        'POST /api/v1/events/{id}/submit' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1069)'],
        'POST /api/v1/events/{id}/approve' => ['policy' => self::STAFF, 'params' => ['id' => 'event'], 'basis' => 'macierz: moderacja — Moderator ✔ (plan line 1092)'],
        'POST /api/v1/events/{id}/reject' => ['policy' => self::STAFF, 'params' => ['id' => 'event'], 'basis' => 'idem (plan line 1111)'],
        'POST /api/v1/events/{id}/cancel' => ['policy' => self::EVENT_OWNER_OR_ADMIN, 'params' => ['id' => 'event'], 'basis' => 'E1.1; spec 13 pkt 2 (plan line 1131)'],
        'PATCH /api/v1/events/{id}/visibility' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'E1.1 (plan line 1157)'],
        'PATCH /api/v1/events/{id}/is-test' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => '(published → 409 for managers = PASS) (plan line 1177)'],
        'GET /api/v1/organizers/{organizerId}/events' => ['policy' => self::EVENT_MANAGER, 'params' => ['organizerId' => 'organizer'], 'override' => ['coManager' => 403, 'coManagerOrg' => 403], 'basis' => 'own list or staff (plan line 1191); R-01: a Co-manager reads only its own panel list (targeted test), never the Owner\'s'],
        'GET /api/v1/organizers/{organizerId}/event-limit' => ['policy' => self::EVENT_MANAGER, 'params' => ['organizerId' => 'organizer'], 'override' => ['coManager' => 403, 'coManagerOrg' => 403], 'basis' => 'idem (plan line 1199); R-01: own limit or staff only, a Co-manager has no Event slots of its own'],
        'PATCH /api/v1/organizers/{organizerId}/event-limit' => ['policy' => self::ADMIN, 'params' => ['organizerId' => 'organizer'], 'basis' => 'pakiety = Admin (plan line 1207)'],
        'GET /api/v1/registrations' => ['policy' => self::STAFF, 'basis' => 'PP.2 (plan line 1228)'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/teams' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event', 'distanceId' => 'relay'], 'basis' => 'E7.1 (NEW-C note) (plan line 1239)'],
        'GET /api/v1/teams/{teamId}' => ['policy' => self::PUBLIC, 'params' => ['teamId' => 'team'], 'basis' => 'PP.54 (NEW-C note) (plan line 1254)'],
        'GET /api/v1/teams/{teamId}/invite' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['teamId' => 'team'], 'basis' => 'PP.54: captain or Event manager (plan line 1265)'],
        'PATCH /api/v1/teams/{teamId}/special-category' => ['policy' => self::CAPTAIN_ONLY, 'params' => ['teamId' => 'team'], 'basis' => 'E7.5 (plan line 1291)'],
        'GET /api/v1/teams/{teamId}/members' => ['policy' => self::CAPTAIN_OR_MANAGER, 'params' => ['teamId' => 'team'], 'basis' => 'G-04: skład do wymiany — kapitan (owner) lub Zespół Wydarzenia (spec 23 §2)'],
        'POST /api/v1/teams/{teamId}/members/{registrationId}/swap' => ['policy' => self::CAPTAIN_OR_MANAGER, 'params' => ['teamId' => 'team', 'registrationId' => 'teamMember'], 'basis' => 'G-04: spec 23 §2 + spec 20 transfer — kapitan (owner) lub Zespół Wydarzenia; moderator = współzarządzający (macierz §3, spec 09)'],
        'GET /api/v1/events/{eventId}/registrations' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'PP.2 (plan line 1321)'],
        'GET /api/v1/events/{eventId}/start-list' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'override' => self::allActors(self::OK), 'basis' => 'PP.3 public DTO (plan line 1332)'],
        'GET /api/v1/events/{eventId}/bz/participants' => ['policy' => self::API_KEY, 'params' => ['eventId' => 'event'], 'basis' => 'E3.3 (plan line 1362)'],
        'PATCH /api/v1/events/{eventId}/bz/participants/{registrationId}' => ['policy' => self::API_KEY, 'params' => ['eventId' => 'event', 'registrationId' => 'registration'], 'basis' => 'E3.5 (plan line 1416)'],
        'GET /api/v1/events/{eventId}/form-fields/public' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => 'E1.13 (NEW-C note) (plan line 1504)'],
        'GET /api/v1/events/{eventId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1509)'],
        'POST /api/v1/events/{eventId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1516)'],
        'PATCH /api/v1/events/{eventId}/form-fields/{fieldId}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'fieldId' => 'formField'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1528)'],
        'DELETE /api/v1/events/{eventId}/form-fields/{fieldId}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'fieldId' => 'formField'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1560)'],
        'GET /api/v1/events/{id}/form-field-aggregates' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1575)'],
        'GET /api/v1/events/{id}/registration-stats' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1587)'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/start-list-export' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'spec 21 (plan line 1603)'],
        'POST /api/v1/events/{eventId}/discount-code-batches' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'R-07 ticket 07 pkt 7; same gate as start-list-export'],
        'GET /api/v1/events/{eventId}/discount-code-batches' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'R-07 ticket 07 pkt 7; same gate as start-list-export'],
        'GET /api/v1/events/{eventId}/discount-code-batches/{batchId}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'batchId' => 'discountBatch'], 'basis' => 'R-07 ticket 07 pkt 7; same gate as start-list-export'],
        'GET /api/v1/events/{eventId}/discount-code-batches/{batchId}/export' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'batchId' => 'discountBatch'], 'basis' => 'R-07 ticket 07 pkt 7; same gate as start-list-export'],
        'PUT /api/v1/events/{eventId}/form-fields/reorder' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 1658)'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'R-04 per-Dystans form: event manager (owner, moderator, admin)'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/form-fields' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'R-04 per-Dystans form: event manager (owner, moderator, admin)'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/form-fields/{fieldId}/override' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist', 'fieldId' => 'formField'], 'basis' => 'R-04 per-Dystans form: event manager (owner, moderator, admin)'],
        'DELETE /api/v1/events/{eventId}/distances/{distanceId}/form-fields/{fieldId}/override' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist', 'fieldId' => 'formField'], 'basis' => 'R-04 per-Dystans form: event manager (owner, moderator, admin)'],
        'PUT /api/v1/registrations/{registrationId}/form-field-answers/{fieldId}' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['registrationId' => 'registration', 'fieldId' => 'formField'], 'basis' => 'PP.2 (plan line 1674)'],
        'GET /api/v1/registrations/{registrationId}/form-field-answers' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['registrationId' => 'registration'], 'basis' => 'PP.2 (plan line 1700)'],
        'GET /api/v1/users/{userId}/registrations' => ['policy' => self::SELF_OR_STAFF, 'params' => ['userId' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'PP.2 (plan line 1710)'],
        'POST /api/v1/registrations' => ['policy' => self::AUTH, 'basis' => 'PP.52 (Opieka); H11 in targeted test (plan line 1717)'],
        'POST /api/v1/registrations/quote' => ['policy' => self::AUTH, 'basis' => 'B-02: same gate as POST /registrations (Opieka, event visibility); writes nothing'],
        'PATCH /api/v1/registrations/{id}' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'PP.2 (plan line 1830)'],
        'POST /api/v1/events/{eventId}/bib-numbers/assign' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'R-13 contract 5.1: event manager assigns start numbers from the distance ranges'],
        'POST /api/v1/registrations/{id}/cancel' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'PP.2 (plan line 1849)'],
        'GET /api/v1/registrations/{id}/changes' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'R-02: history of one Zapis, owner + manager (zakres P2)'],
        'POST /api/v1/registrations/{id}/distance-change/quote' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'R-02: what a change of Dystans would cost, writes nothing (zakres P2)'],
        'POST /api/v1/registrations/{id}/distance-change' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'R-02: owner inside the self-service window, manager always (zakres P2)'],
        'POST /api/v1/registrations/{id}/transfer' => ['policy' => self::REG_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'R-02: transfer of a Zapis to another Zawodnik, the Event manager only (zakres P2)'],
        'PATCH /api/v1/registrations/{id}/payment-details' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6 (plan line 1860)'],
        'POST /api/v1/registrations/{id}/refund-request' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'spec 13: zawodnik prosi (plan line 1875)'],
        'POST /api/v1/registrations/{id}/refund-approve' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6 (plan line 1894)'],
        'POST /api/v1/registrations/{id}/refund-complete' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6 (plan line 1914)'],
        'POST /api/v1/registrations/{id}/retry-payment' => ['policy' => self::REG_OWNER_OR_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'restarts the participant\'s own session (plan line 1936)'],
        'POST /api/v1/registrations/{id}/mark-paid' => ['policy' => self::REG_MONEY, 'params' => ['id' => 'registration'], 'basis' => 'H6 (plan line 1969)'],
        'PATCH /api/v1/registrations/{id}/form-snapshot' => ['policy' => self::REG_MANAGER, 'params' => ['id' => 'registration'], 'basis' => 'E1.14 (plan line 2008)'],
        'POST /api/v1/webhooks/tpay' => ['policy' => self::SIGNED_WEBHOOK, 'basis' => 'PP.6 (plan line 2062)'],
        'POST /api/v1/webhooks/paynow' => ['policy' => self::SIGNED_WEBHOOK, 'basis' => 'PP.6 (plan line 2127)'],
        'GET /api/v1/virtual-results' => ['policy' => self::AUTH, 'basis' => 'non-managers forced to their own rows (:2203) (plan line 2184)'],
        'POST /api/v1/virtual-results' => ['policy' => self::PARTICIPANT_ROLE, 'basis' => 'PARTICIPANT_ROLE (no extra per-row rule) (plan line 2207)'],
        'PATCH /api/v1/virtual-results/{id}/review' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'virtualSubmission'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2235)'],
        'GET /api/v1/results' => ['policy' => self::PUBLIC, 'basis' => 'results are public (spec 14) (plan line 2274)'],
        'GET /api/v1/events/{eventId}/results' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => '(NEW-C note) (plan line 2278)'],
        'GET /api/v1/users/{userId}/results' => ['policy' => self::PUBLIC, 'params' => ['userId' => 'owner'], 'basis' => 'athlete profile (PP.1 note :700-702) (plan line 2279)'],
        'POST /api/v1/results' => ['policy' => self::EVENT_MANAGER, 'body' => ['eventId' => '@event', 'distanceId' => '@dist'], 'basis' => '$canUploadResults reads the body\'s Event (plan line 2280)'],
        'PATCH /api/v1/results/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'result'], 'basis' => 'H7 in targeted test (plan line 2292)'],
        'GET /api/v1/results/{id}/corrections' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'result'], 'basis' => 'G-03: same right as editing the result (spec 14 pkt 6)'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/results/publish-official' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2330)'],
        'DELETE /api/v1/results/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'result'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2353)'],
        'GET /api/v1/users/{userId}/personal-records' => ['policy' => self::PUBLIC, 'params' => ['userId' => 'owner'], 'basis' => 'public records (plan line 2367)'],
        'GET /api/v1/competitors/{id}/records' => ['policy' => self::CARE_OR_STAFF, 'params' => ['id' => 'child'], 'basis' => 'E6.6 (plan line 2378)'],
        'GET /api/v1/events/{eventId}/team-results' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => '(NEW-C note) (plan line 2394)'],
        'GET /api/v1/events/{eventId}/distances/{distanceId}/team-results' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event', 'distanceId' => 'relay'], 'basis' => '(NEW-C note) (plan line 2400)'],
        'GET /api/v1/events/{eventId}/result-documents' => ['policy' => self::PUBLIC, 'params' => ['eventId' => 'event'], 'basis' => '(NEW-C note) (plan line 2401)'],
        'POST /api/v1/events/{eventId}/result-documents' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2402)'],
        'POST /api/v1/events/{eventId}/distances/{distanceId}/result-imports' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'distanceId' => 'dist'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2417)'],
        'GET /api/v1/result-imports/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importBatch'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2458)'],
        'PATCH /api/v1/result-imports/{id}/mapping' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importBatch'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2469)'],
        'PATCH /api/v1/result-import-rows/{id}' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importRow'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2486)'],
        'POST /api/v1/result-import-rows/{id}/create-registration' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importRow'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2509)'],
        'POST /api/v1/result-imports/{id}/commit' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'importBatch'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 2536)'],
        'GET /api/v1/series' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2556)'],
        'GET /api/v1/series/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2566)'],
        'GET /api/v1/series/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2567)'],
        'GET /api/v1/series/{id}/ranking' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2576)'],
        'POST /api/v1/series' => ['policy' => self::STAFF, 'basis' => '§3.6 (matrix silent) (plan line 2584)'],
        'PATCH /api/v1/series/{id}' => ['policy' => self::STAFF, 'basis' => '§3.6 (plan line 2591)'],
        'POST /api/v1/series/{id}/archive' => ['policy' => self::STAFF, 'basis' => '§3.6 (plan line 2599)'],
        'GET /api/v1/shop/categories' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2607)'],
        'POST /api/v1/shop/categories' => ['policy' => self::ADMIN, 'basis' => 'macierz: Produkty sklepowe = Admin (plan line 2612)'],
        'PATCH /api/v1/shop/categories/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 2624)'],
        'DELETE /api/v1/shop/categories/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 2636)'],
        'GET /api/v1/shop/delivery-methods' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2657)'],
        'GET /api/v1/shop/payment-methods' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2662)'],
        'GET /api/v1/calendar/settings' => ['policy' => self::ADMIN, 'basis' => 'R-17: Google Calendar settings = Admin (umowa 5.9)'],
        'PATCH /api/v1/calendar/settings' => ['policy' => self::ADMIN, 'basis' => 'R-17: idem'],
        'POST /api/v1/calendar/events' => ['policy' => self::ADMIN, 'basis' => 'R-17: manual send to calendar = Admin'],
        'GET /api/v1/insurance/settings' => ['policy' => self::PUBLIC, 'basis' => 'read by the registration form (plan line 2668)'],
        'PATCH /api/v1/insurance/settings' => ['policy' => self::ADMIN, 'basis' => 'macierz: Ustawienia globalne (NNW) = Admin (plan line 2669)'],
        'GET /api/v1/insurance/variants' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2680)'],
        'POST /api/v1/insurance/variants' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 2681)'],
        'PATCH /api/v1/insurance/variants/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 2688)'],
        'DELETE /api/v1/insurance/variants/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 2696)'],
        'GET /api/v1/shop/orders' => ['policy' => self::STAFF, 'basis' => 'staff full projection (macierz §3 Zamówienia sklepowe: Moderator/Admin); participant own orders = GET /me/orders (PP.17)'],
        'GET /api/v1/shop/orders/{id}' => ['policy' => self::STAFF, 'basis' => 'idem; own single order = GET /me/orders/{id} (PP.17)'],
        'POST /api/v1/shop/orders' => ['policy' => self::AUTH, 'basis' => 'buyer = auth.sub (plan line 2709)'],
        'GET /api/v1/me/orders' => ['policy' => self::AUTH, 'override' => ['participant' => self::OK, 'owner' => self::OK, 'foreignOrganizer' => self::OK, 'organizer' => self::OK, 'moderator' => self::OK, 'admin' => self::OK, 'superadmin' => self::OK, 'coManager' => self::OK, 'coManagerOrg' => self::OK, 'revoked' => self::OK], 'basis' => 'macierz §3 Zamówienia sklepowe — Uczestnik: własne; identity = auth.sub (PP.17)'],
        'GET /api/v1/me/orders/{id}' => ['policy' => self::SELF_ONLY, 'params' => ['id' => 'order'], 'basis' => 'macierz §3 własne; foreign id = 404, not 403 (no enumeration) (PP.17)'],
        'PATCH /api/v1/shop/orders/{id}/status' => ['policy' => self::STAFF, 'params' => ['id' => 'order'], 'body' => ['status' => 'shipped'], 'basis' => 'macierz: Moderator "tylko zmiana statusu" (§8 Q1) (plan line 2752)'],
        'PATCH /api/v1/shop/orders/{id}' => ['policy' => self::ADMIN, 'basis' => 'H1 (plan line 2765)'],
        'GET /api/v1/shop/products' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2772)'],
        'GET /api/v1/shop/products/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2782)'],
        'GET /api/v1/shop/products/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2783)'],
        'POST /api/v1/shop/products' => ['policy' => self::ADMIN, 'basis' => 'macierz: Produkty = Admin (plan line 2793)'],
        'PATCH /api/v1/shop/products/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 2812)'],
        'DELETE /api/v1/shop/products/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 2832)'],
        'GET /api/v1/challenges' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2840)'],
        'GET /api/v1/challenges/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2849)'],
        'GET /api/v1/challenges/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2850)'],
        'POST /api/v1/challenges' => ['policy' => self::ADMIN, 'basis' => 'H2 (plan line 2851)'],
        'PATCH /api/v1/challenges/{id}' => ['policy' => self::ADMIN, 'basis' => 'H2 (plan line 2865)'],
        'GET /api/v1/challenges/{id}/condition-tree' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2888)'],
        'PUT /api/v1/challenges/{id}/condition-tree' => ['policy' => self::ADMIN, 'basis' => 'H2 (plan line 2889)'],
        'GET /api/v1/challenge-points-settings' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2902)'],
        'PATCH /api/v1/challenge-points-settings' => ['policy' => self::ADMIN, 'basis' => 'E6.2 (plan line 2903)'],
        'POST /api/v1/challenges/{id}/condition-tree/point-score/{userId}' => ['policy' => self::AUTH, 'params' => ['userId' => 'owner'], 'basis' => '§3.6 (plan line 2924)'],
        'GET /api/v1/challenges/{id}/ranking' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 2940)'],
        'POST /api/v1/challenges/{id}/archive' => ['policy' => self::ADMIN, 'basis' => 'H2 (plan line 2948)'],
        'GET /api/v1/challenge-participations' => ['policy' => self::AUTH, 'basis' => '§3.6 (plan line 2955)'],
        'POST /api/v1/challenges/{id}/join' => ['policy' => self::AUTH, 'basis' => 'self (auth.sub) (plan line 2957)'],
        'POST /api/v1/challenge-entries' => ['policy' => self::AUTH, 'basis' => 'H3 in targeted test (plan line 2972)'],
        'POST /api/v1/challenges/{id}/claim-reward' => ['policy' => self::AUTH, 'basis' => 'self (auth.sub) (plan line 2989)'],
        'PATCH /api/v1/challenge-entries/{id}/review' => ['policy' => self::STAFF, 'basis' => 'macierz: weryfikacja Zgłoszeń — Moderator ✔ (plan line 3030)'],
        'GET /api/v1/info-pages' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 3045)'],
        'GET /api/v1/info-pages/slug/{slug}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 3046)'],
        'POST /api/v1/info-pages' => ['policy' => self::ADMIN, 'basis' => 'macierz: strony CMS = Admin (plan line 3047)'],
        'PATCH /api/v1/info-pages/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 3060)'],
        'DELETE /api/v1/info-pages/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 3074)'],
        'POST /api/v1/info-pages/reorder' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 3081)'],
        'GET /api/v1/partners' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 3088)'],
        'GET /api/v1/partners/featured' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 3089)'],
        'GET /api/v1/partners/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 3090)'],
        'POST /api/v1/partners' => ['policy' => self::ADMIN, 'basis' => 'macierz: Partnerzy = Admin (plan line 3091)'],
        'PATCH /api/v1/partners/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 3108)'],
        'DELETE /api/v1/partners/{id}' => ['policy' => self::ADMIN, 'basis' => 'idem (plan line 3129)'],
        'GET /api/v1/event-backgrounds' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 3137)'],
        'GET /api/v1/event-backgrounds/{id}' => ['policy' => self::PUBLIC, 'basis' => 'public read/endpoint (no extra per-row rule) (plan line 3138)'],
        'GET /api/v1/event-backgrounds/{id}/usages' => ['policy' => self::STAFF, 'basis' => '§3.6 (plan line 3139)'],
        'POST /api/v1/uploads/event-backgrounds' => ['policy' => self::STAFF, 'basis' => '§3.6 (plan line 3141)'],
        'POST /api/v1/event-backgrounds' => ['policy' => self::STAFF, 'basis' => '§3.6 (plan line 3163)'],
        'PATCH /api/v1/event-backgrounds/{id}' => ['policy' => self::STAFF, 'basis' => '§3.6 (plan line 3177)'],
        'DELETE /api/v1/event-backgrounds/{id}' => ['policy' => self::STAFF, 'basis' => '§3.6 (plan line 3192)'],
        'GET /api/v1/admin/activity-logs' => ['policy' => self::ADMIN, 'basis' => 'macierz §5: odczyt globalny = Admin (plan line 3210)'],
        'GET /api/v1/events/{id}/activity-logs' => ['policy' => self::EVENT_MANAGER, 'params' => ['id' => 'event'], 'basis' => 'macierz: wycinek — Właściciel/Moderator/Admin (plan line 3212)'],
        'GET /api/v1/admin/security-settings' => ['policy' => self::ADMIN, 'basis' => 'admin-only (macierz section 3) (no extra per-row rule) (plan line 3223)'],
        'PATCH /api/v1/admin/security-settings' => ['policy' => self::ADMIN, 'basis' => 'admin-only (macierz section 3) (no extra per-row rule) (plan line 3225)'],
        'GET /api/v1/admin/online-sessions' => ['policy' => self::ADMIN, 'basis' => 'H10 (plan line 3231)'],
        'GET /api/v1/admin/sales-stats' => ['policy' => self::ADMIN, 'basis' => 'admin-only (G-05, same as platform-stats)'],
        'GET /api/v1/admin/platform-stats' => ['policy' => self::ADMIN, 'basis' => 'admin-only (macierz section 3) (no extra per-row rule) (plan line 3239)'],
        'GET /api/v1/admin/settlements' => ['policy' => self::ADMIN, 'basis' => 'admin-only: raport rozliczeniowy trasy A (spec 13), organizator 403'],
        'GET /api/v1/admin/settlements/export' => ['policy' => self::ADMIN, 'basis' => 'admin-only: eksport raportu rozliczeniowego (spec 13/21)'],
        'GET /api/v1/users/{userId}/tickets' => ['policy' => self::SELF_OR_STAFF, 'params' => ['userId' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'PP.2 (plan line 3244)'],
        'GET /api/v1/users/{userId}/certificates' => ['policy' => self::SELF_OR_STAFF, 'params' => ['userId' => 'owner'], 'override' => ['owner' => self::OK], 'basis' => 'PP.2 (plan line 3251)'],
        'POST /api/v1/dev/seed-event' => ['policy' => self::ENV_GATED, 'basis' => '§3.6 (plan line 3262)'],
        'GET /api/v1/competitors/duplicates' => ['policy' => self::AUTH, 'basis' => 'H9 in targeted test (shape) (plan line 3589)'],
        'GET /api/v1/care' => ['policy' => self::AUTH, 'basis' => 'own data (auth.sub) (plan line 3601)'],
        'POST /api/v1/care' => ['policy' => self::PARTICIPANT_ROLE, 'basis' => ':3624-3626 (observation §1) (plan line 3620)'],
        'DELETE /api/v1/care/{id}' => ['policy' => self::CARE_REVOKE, 'params' => ['id' => 'care'], 'basis' => 'E1.12 (plan line 3687)'],
        'POST /api/v1/competitors/{id}/rotate-identity-code' => ['policy' => self::CARE_HOLDER, 'params' => ['id' => 'child'], 'basis' => 'spec 11 §2 (plan line 3707)'],
        'POST /api/v1/competitors/{id}/anonymization' => ['policy' => self::CARE_HOLDER, 'params' => ['id' => 'child'], 'basis' => 'E6.6 (plan line 3729)'],
        'POST /api/v1/competitors/{id}/duplicate-reports' => ['policy' => self::CARE_OR_MANAGER_ROLE, 'params' => ['id' => 'child'], 'basis' => 'R-08 (spec 22): opiekun, organizator zawodnika zapisanego na jego wydarzenie albo staff'],
        'PATCH /api/v1/admin/competitors/duplicate-reports/{id}' => ['policy' => self::STAFF, 'params' => ['id' => 'dupReport'], 'basis' => 'R-08: odrzucenie zgłoszenia = Moderator/Admin'],
        'GET /api/v1/admin/competitors/duplicate-reports' => ['policy' => self::STAFF, 'basis' => 'R-08: kolejka zgłoszeń = Moderator/Admin, jak scalanie'],
        'GET /api/v1/admin/competitors/search' => ['policy' => self::STAFF, 'basis' => 'macierz: Scalanie — Moderator ✔ (500 before PP.14 still = PASS) (plan line 3756)'],
        'GET /api/v1/admin/competitors/{idA}/merge-comparison/{idB}' => ['policy' => self::STAFF, 'params' => ['idA' => 'child', 'idB' => 'competitorB'], 'basis' => 'idem (plan line 3764)'],
        'POST /api/v1/admin/competitors/merge' => ['policy' => self::STAFF, 'basis' => 'idem (empty body → 422) (plan line 3778)'],
        'GET /api/v1/admin/mail-templates' => ['policy' => self::ADMIN, 'basis' => 'macierz: szablony globalne = Admin (plan line 3830)'],
        'PUT /api/v1/admin/mail-templates/{mailType}' => ['policy' => self::ADMIN, 'params' => ['mailType' => 'mailType'], 'basis' => 'idem (plan line 3837)'],
        'GET /api/v1/events/{eventId}/mail-templates' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'macierz: szablony Wydarzenia — Zespół/Moderator/Admin (plan line 3854)'],
        'PUT /api/v1/events/{eventId}/mail-templates/{mailType}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'mailType' => 'mailType'], 'basis' => 'idem (plan line 3861)'],
        'DELETE /api/v1/events/{eventId}/mail-templates/{mailType}' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'mailType' => 'mailType'], 'basis' => 'idem (plan line 3882)'],
        'POST /api/v1/admin/mail-templates/{mailType}/preview' => ['policy' => self::ADMIN, 'params' => ['mailType' => 'mailType'], 'basis' => 'B-03: podgląd szablonu globalnego = Admin (jak PUT)'],
        'POST /api/v1/events/{eventId}/mail-templates/{mailType}/preview' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event', 'mailType' => 'mailType'], 'basis' => 'B-03: podgląd szablonu Wydarzenia = zespół (jak PUT)'],
        'GET /api/v1/admin/mail-log' => ['policy' => self::ADMIN, 'basis' => 'B-03: dziennik wysyłek globalnie = Admin'],
        'GET /api/v1/events/{eventId}/mail-log' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'B-03: dziennik wysyłek Wydarzenia = zespół'],
        'GET /api/v1/events/{eventId}/mail-send-progress' => ['policy' => self::EVENT_MANAGER, 'params' => ['eventId' => 'event'], 'basis' => 'event manager (owner, moderator, admin) (no extra per-row rule) (plan line 3898)'],
        ];
    }
}
