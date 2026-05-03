<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Domain\Cms\InfoPageRepository;
use ZmierzymyCzas\Domain\Cms\PartnerRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Series\SeriesRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Http\Middleware\AuthMiddleware;
use ZmierzymyCzas\Support\JsonResponder;

return static function (App $app, PDO $pdo, JwtService $jwt): void {
    $users = new UserRepository($pdo);
    $events = new EventRepository($pdo);
    $registrations = new RegistrationRepository($pdo);
    $results = new ResultRepository($pdo);
    $series = new SeriesRepository($pdo);
    $pages = new InfoPageRepository($pdo);
    $partners = new PartnerRepository($pdo);
    $admin = new AdminRepository($pdo);

    $body = static fn (Request $request): array => is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
    $query = static fn (Request $request): array => $request->getQueryParams();
    $adminRoles = ['admin', 'superadmin'];
    $staffRoles = ['admin', 'superadmin', 'moderator'];
    $eventManagerRoles = ['organizer', 'admin', 'superadmin', 'moderator'];
    $publisherRoles = ['admin', 'superadmin'];
    $publicEventStatuses = ['published', 'completed'];
    $isSuperAdmin = static fn (Request $request): bool => ((string) (((array) $request->getAttribute('auth'))['role'] ?? '')) === 'superadmin';
    $isStaffRole = static fn (string $role): bool => in_array($role, ['admin', 'superadmin', 'moderator'], true);
    $optionalAuth = static function (Request $request) use ($jwt): array {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return [];
        }
        try {
            return $jwt->decode($matches[1]);
        } catch (Throwable) {
            return [];
        }
    };
    $canViewEvent = static function (?array $event, array $auth) use ($publicEventStatuses, $isStaffRole): bool {
        if (!$event) {
            return false;
        }
        if (in_array((string) $event['status'], $publicEventStatuses, true)) {
            return true;
        }
        $role = (string) ($auth['role'] ?? '');
        $userId = (string) ($auth['sub'] ?? '');
        return $isStaffRole($role) || ($role === 'organizer' && $userId !== '' && $event['organizerId'] === $userId);
    };
    $canManageEvent = static function (?array $event, array $auth) use ($isStaffRole): bool {
        if (!$event) {
            return false;
        }
        $role = (string) ($auth['role'] ?? '');
        $userId = (string) ($auth['sub'] ?? '');
        return $isStaffRole($role) || ($role === 'organizer' && $userId !== '' && $event['organizerId'] === $userId);
    };
    $touchesAdminAccount = static function (?array $existing, array $payload): bool {
        $existingRole = (string) ($existing['role'] ?? '');
        $nextRole = array_key_exists('role', $payload) ? (string) $payload['role'] : '';
        return in_array($existingRole, ['admin', 'superadmin'], true) || in_array($nextRole, ['admin', 'superadmin'], true);
    };

    $app->group('/api/v1', function (RouteCollectorProxy $api) use ($jwt, $users, $events, $registrations, $results, $series, $pages, $partners, $admin, $body, $query, $adminRoles, $staffRoles, $eventManagerRoles, $publisherRoles, $optionalAuth, $isStaffRole, $canViewEvent, $canManageEvent, $isSuperAdmin, $touchesAdminAccount): void {
        $api->get('/health', static fn (Request $request, Response $response) => JsonResponder::ok($response, ['status' => 'ok']));

        $api->post('/auth/login', function (Request $request, Response $response) use ($users, $jwt, $body) {
            $payload = $body($request);
            $user = $users->findByEmail((string) ($payload['email'] ?? ''), true);
            if (!$user || !password_verify((string) ($payload['password'] ?? ''), (string) $user['passwordHash'])) {
                return JsonResponder::error($response, 'invalid_credentials', 'Invalid email or password', 401);
            }
            unset($user['passwordHash']);
            $token = $jwt->issue(['sub' => $user['id'], 'role' => $user['role'], 'email' => $user['email']]);
            return JsonResponder::ok($response, ['user' => $user, 'token' => $token]);
        });

        $api->post('/auth/register', function (Request $request, Response $response) use ($users, $body) {
            $payload = $body($request);
            if (empty($payload['name']) || empty($payload['email']) || empty($payload['password'])) {
                return JsonResponder::error($response, 'validation_error', 'Name, email and password are required', 422);
            }
            if ($users->findByEmail((string) $payload['email'])) {
                return JsonResponder::error($response, 'email_taken', 'Email is already registered', 409);
            }
            return JsonResponder::ok($response, $users->create($payload), 'User registered', 201);
        });

        $api->post('/auth/logout', static fn (Request $request, Response $response) => JsonResponder::ok($response, null));
        $api->post('/auth/password/reset', static fn (Request $request, Response $response) => JsonResponder::ok($response, null, 'Password reset email queued'));

        $api->get('/auth/me', function (Request $request, Response $response) use ($users) {
            $auth = (array) $request->getAttribute('auth');
            return JsonResponder::ok($response, $users->find((string) ($auth['sub'] ?? '')));
        })->add(new AuthMiddleware($jwt));

        $api->post('/auth/password/change', static fn (Request $request, Response $response) => JsonResponder::ok($response, null, 'Password changed'))
            ->add(new AuthMiddleware($jwt));

        $api->get('/users', function (Request $request, Response $response) use ($users, $query) {
            $page = $users->list($query($request));
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->get('/users/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $users->find($args['id'])));
        $api->post('/users', function (Request $request, Response $response) use ($users, $body, $isSuperAdmin) {
            $payload = $body($request);
            if (in_array((string) ($payload['role'] ?? ''), ['admin', 'superadmin'], true) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can create admin accounts', 403);
            }
            return JsonResponder::ok($response, $users->create($payload), 'User created', 201);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/users/{id}', function (Request $request, Response $response, array $args) use ($users, $body, $isSuperAdmin, $touchesAdminAccount) {
            $payload = $body($request);
            $existing = $users->find($args['id']);
            if ($touchesAdminAccount($existing, $payload) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can modify admin accounts', 403);
            }
            return JsonResponder::ok($response, $users->update($args['id'], $payload));
        })
            ->add(new AuthMiddleware($jwt));
        $api->delete('/users/{id}', function (Request $request, Response $response, array $args) use ($users, $isSuperAdmin, $touchesAdminAccount) {
            if ($touchesAdminAccount($users->find($args['id']), []) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can delete admin accounts', 403);
            }
            $users->delete($args['id']);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/users/{id}/role', function (Request $request, Response $response, array $args) use ($users, $body, $isSuperAdmin, $touchesAdminAccount) {
            $payload = ['role' => $body($request)['role'] ?? null];
            if ($touchesAdminAccount($users->find($args['id']), $payload) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can modify admin roles', 403);
            }
            return JsonResponder::ok($response, $users->update($args['id'], $payload));
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/users/{id}/status', function (Request $request, Response $response, array $args) use ($users, $body, $isSuperAdmin, $touchesAdminAccount) {
            if ($touchesAdminAccount($users->find($args['id']), []) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can change admin status', 403);
            }
            return JsonResponder::ok($response, $users->update($args['id'], ['status' => $body($request)['status'] ?? null]));
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->get('/participants', function (Request $request, Response $response) use ($users) {
            $page = $users->list(['role' => 'participant', 'pageSize' => 100]);
            return JsonResponder::ok($response, $page['data']);
        });
        $api->get('/organizers', function (Request $request, Response $response) use ($users) {
            $page = $users->list(['role' => 'organizer', 'pageSize' => 100]);
            return JsonResponder::ok($response, $page['data']);
        });

        $api->get('/events', function (Request $request, Response $response) use ($events, $query, $optionalAuth, $isStaffRole) {
            $filters = $query($request);
            $auth = $optionalAuth($request);
            if (!empty($filters['includeUnpublished']) && $isStaffRole((string) ($auth['role'] ?? ''))) {
                $filters['includeUnpublished'] = true;
                $filters['includePrivate'] = true;
            } else {
                unset($filters['includeUnpublished']);
            }
            $page = $events->list($filters);
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        });
        $api->get('/events/featured', fn (Request $request, Response $response) => JsonResponder::ok($response, $events->featured()));
        $api->get('/events/live', fn (Request $request, Response $response) => JsonResponder::ok($response, $events->live()));
        $api->get('/events/slug/{slug}', function (Request $request, Response $response, array $args) use ($events, $optionalAuth, $canViewEvent) {
            $event = $events->findBySlug($args['slug']);
            if (!$canViewEvent($event, $optionalAuth($request))) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }
            return JsonResponder::ok($response, $event);
        });
        $api->get('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $optionalAuth, $canViewEvent) {
            $event = $events->find($args['id']);
            if (!$canViewEvent($event, $optionalAuth($request))) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }
            return JsonResponder::ok($response, $event);
        });
        $api->post('/events', function (Request $request, Response $response) use ($events, $users, $body, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $payload = $body($request);
            $role = (string) ($auth['role'] ?? '');
            $organizerId = $role === 'organizer' ? (string) ($auth['sub'] ?? '') : (string) ($payload['organizerId'] ?? '');
            $organizer = $users->find($organizerId);
            if (!$organizer || $organizer['role'] !== 'organizer' || ($organizer['status'] ?? 'active') !== 'active') {
                return JsonResponder::error($response, 'invalid_organizer', 'Active organizer is required', 422);
            }
            if ($role !== 'organizer' && !$isStaffRole($role)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            try {
                return JsonResponder::ok($response, $events->createForOrganizer($payload, $organizer, (string) ($auth['sub'] ?? '')), 'Event created', 201);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'no_event_credits') {
                    return JsonResponder::error($response, 'no_event_credits', 'Organizer has no available event credits', 409);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $users, $body, $canManageEvent, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            $payload = $body($request);
            $role = (string) ($auth['role'] ?? '');
            if ($role === 'organizer') {
                unset($payload['organizerId'], $payload['organizerName'], $payload['status']);
            } elseif (array_key_exists('organizerId', $payload) && ($payload['organizerId'] ?? '') !== ($event['organizerId'] ?? '')) {
                if (($event['status'] ?? '') === 'published') {
                    return JsonResponder::error($response, 'published_organizer_locked', 'Published events cannot be transferred to another organizer', 409);
                }
                $organizer = $users->find((string) $payload['organizerId']);
                if (!$organizer || $organizer['role'] !== 'organizer' || ($organizer['status'] ?? 'active') !== 'active') {
                    return JsonResponder::error($response, 'invalid_organizer', 'Active organizer is required', 422);
                }
                try {
                    $events->transferOrganizer($args['id'], $organizer);
                } catch (RuntimeException $e) {
                    if ($e->getMessage() === 'no_event_credits') {
                        return JsonResponder::error($response, 'no_event_credits', 'Organizer has no available event credits', 409);
                    }
                    throw $e;
                }
                unset($payload['organizerId'], $payload['organizerName']);
            } elseif (!$isStaffRole($role)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            return JsonResponder::ok($response, $events->update($args['id'], $payload));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->delete('/events/{id}', function (Request $request, Response $response, array $args) use ($events) {
            $events->delete($args['id']);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, ['admin']));
        $api->patch('/events/{id}/status', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $events->updateStatus($args['id'], (string) ($body($request)['status'] ?? 'draft'), $body($request)['reason'] ?? null)))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/events/{id}/submit', function (Request $request, Response $response, array $args) use ($events, $canManageEvent) {
            $auth = (array) $request->getAttribute('auth');
            if (!$canManageEvent($events->find($args['id']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            $event = $events->submit($args['id']);
            return $event ? JsonResponder::ok($response, $event) : JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be submitted in its current status', 409);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->post('/events/{id}/approve', function (Request $request, Response $response, array $args) use ($events) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->approve($args['id'], (string) ($auth['sub'] ?? ''));
            return $event ? JsonResponder::ok($response, $event) : JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be approved in its current status', 409);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/events/{id}/reject', function (Request $request, Response $response, array $args) use ($events, $body) {
            $reason = trim((string) ($body($request)['reason'] ?? ''));
            if ($reason === '') {
                return JsonResponder::error($response, 'validation_error', 'Rejection reason is required', 422);
            }
            $event = $events->reject($args['id'], $reason);
            return $event ? JsonResponder::ok($response, $event) : JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be rejected in its current status', 409);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/events/{id}/publish', function (Request $request, Response $response, array $args) use ($events, $publisherRoles) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            $role = (string) ($auth['role'] ?? '');
            $isOwner = $role === 'organizer' && ($event['organizerId'] ?? '') === ($auth['sub'] ?? '');
            if (!$isOwner && !in_array($role, $publisherRoles, true)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            $published = $events->publish($args['id'], (string) ($auth['sub'] ?? ''));
            return $published ? JsonResponder::ok($response, $published) : JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be published in its current status', 409);
        })->add(new AuthMiddleware($jwt, ['organizer', 'admin']));
        $api->get('/organizers/{organizerId}/events', function (Request $request, Response $response, array $args) use ($events, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            if (!$isStaffRole($role) && (($auth['sub'] ?? '') !== $args['organizerId'])) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            return JsonResponder::ok($response, $events->byOrganizer($args['organizerId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/organizers/{organizerId}/event-credits', function (Request $request, Response $response, array $args) use ($events, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            if (!$isStaffRole($role) && (($auth['sub'] ?? '') !== $args['organizerId'])) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            return JsonResponder::ok($response, $events->credits($args['organizerId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->post('/organizers/{organizerId}/event-credits', function (Request $request, Response $response, array $args) use ($events, $users, $body, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            if (!$isStaffRole($role) && (($auth['sub'] ?? '') !== $args['organizerId'])) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            $organizer = $users->find($args['organizerId']);
            if (!$organizer || $organizer['role'] !== 'organizer') {
                return JsonResponder::error($response, 'invalid_organizer', 'Organizer is required', 422);
            }
            $payload = $body($request);
            $count = max(1, (int) ($payload['count'] ?? 1));
            $kind = (string) ($payload['kind'] ?? 'standard');
            return JsonResponder::ok($response, $events->grantCredits($args['organizerId'], $count, 'manual_grant', null, $kind), 'Credits granted', 201);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->get('/registrations', function (Request $request, Response $response) use ($registrations, $query) {
            $page = $registrations->list($query($request));
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        })->add(new AuthMiddleware($jwt));
        $api->get('/events/{eventId}/registrations', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->byEvent($args['eventId'])))
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/users/{userId}/registrations', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->byUser($args['userId'])))
            ->add(new AuthMiddleware($jwt));
        $api->post('/registrations', fn (Request $request, Response $response) => JsonResponder::ok($response, $registrations->create($body($request)), 'Registration created', 201))
            ->add(new AuthMiddleware($jwt));
        $api->patch('/registrations/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->update($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt));
        $api->post('/registrations/{id}/cancel', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->cancel($args['id'])))
            ->add(new AuthMiddleware($jwt));
        $api->patch('/registrations/{id}/payment-details', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->updatePaymentDetails($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->get('/results', function (Request $request, Response $response) use ($results, $query) {
            $page = $results->list($query($request));
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        });
        $api->get('/events/{eventId}/results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->byEvent($args['eventId'], $request->getQueryParams()['distanceId'] ?? null)));
        $api->get('/users/{userId}/results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->byUser($args['userId'])));
        $api->post('/results', fn (Request $request, Response $response) => JsonResponder::ok($response, $results->create($body($request)), 'Result created', 201))
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/results/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->update($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->delete('/results/{id}', function (Request $request, Response $response, array $args) use ($results) {
            $results->delete($args['id']);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/users/{userId}/personal-records', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->personalRecords($args['userId'])));
        $api->get('/events/{eventId}/team-results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->teamResults($args['eventId'])));
        $api->get('/events/{eventId}/result-documents', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->documents($args['eventId'])));
        $api->post('/events/{eventId}/result-documents', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->createDocument($args['eventId'], $body($request)), 'Document uploaded', 201))
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->get('/series', function (Request $request, Response $response) use ($series, $query) {
            $page = $series->list($query($request));
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        });
        $api->get('/series/slug/{slug}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $series->findBySlug($args['slug'])));
        $api->get('/series/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $series->find($args['id'])));

        $api->get('/info-pages', fn (Request $request, Response $response) => JsonResponder::ok($response, $pages->list()));
        $api->get('/info-pages/slug/{slug}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $pages->findBySlug($args['slug'])));
        $api->post('/info-pages', fn (Request $request, Response $response) => JsonResponder::ok($response, $pages->create($body($request)), 'Page created', 201))
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->patch('/info-pages/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $pages->update($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->delete('/info-pages/{id}', function (Request $request, Response $response, array $args) use ($pages) {
            $pages->delete($args['id']);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, ['admin']));
        $api->post('/info-pages/reorder', function (Request $request, Response $response) use ($pages, $body) {
            $pages->reorder($body($request)['ids'] ?? []);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, ['admin']));

        $api->get('/partners', fn (Request $request, Response $response) => JsonResponder::ok($response, $partners->list()));
        $api->get('/partners/featured', fn (Request $request, Response $response) => JsonResponder::ok($response, $partners->featured()));

        $api->get('/admin/activity-logs', fn (Request $request, Response $response) => JsonResponder::ok($response, $admin->activityLogs($query($request))))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->get('/admin/security-settings', fn (Request $request, Response $response) => JsonResponder::ok($response, $admin->securitySettings()))
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->patch('/admin/security-settings', fn (Request $request, Response $response) => JsonResponder::ok($response, $admin->updateSecuritySettings($body($request))))
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->get('/admin/online-sessions', fn (Request $request, Response $response) => JsonResponder::ok($response, $admin->onlineSessions()))
            ->add(new AuthMiddleware($jwt, $staffRoles));
    });
};
