<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Cms\InfoPageRepository;
use ZmierzymyCzas\Domain\Cms\PartnerRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Series\SeriesRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Http\Middleware\AuthMiddleware;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\JsonResponder;
use ZmierzymyCzas\Support\UploadedFileStorage;

return static function (App $app, PDO $pdo, JwtService $jwt): void {
    $users = new UserRepository($pdo);
    $events = new EventRepository($pdo);
    $registrations = new RegistrationRepository($pdo);
    $results = new ResultRepository($pdo);
    $series = new SeriesRepository($pdo);
    $shop = new ShopRepository($pdo);
    $challenges = new ChallengeRepository($pdo);
    $pages = new InfoPageRepository($pdo);
    $partners = new PartnerRepository($pdo);
    $admin = new AdminRepository($pdo);
    $uploadDir = (string) Env::get('APP_UPLOAD_DIR', 'public/uploads');
    if (!preg_match('/^(?:[a-z]:[\/\\\\]|\/|\\\\\\\\)/i', $uploadDir)) {
        $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $uploadDir;
    }
    $uploads = new UploadedFileStorage(
        $uploadDir,
        (string) Env::get('APP_UPLOAD_PUBLIC_BASE', '/uploads'),
        (int) Env::get('APP_UPLOAD_MAX_BYTES', 5242880)
    );

    $body = static fn (Request $request): array => is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
    $query = static fn (Request $request): array => $request->getQueryParams();
    $adminRoles = ['admin', 'superadmin'];
    $staffRoles = ['admin', 'superadmin', 'moderator'];
    $eventManagerRoles = ['organizer', 'admin', 'superadmin', 'moderator'];
    $publisherRoles = ['admin', 'superadmin'];
    $publicEventStatuses = ['active', 'past', 'archived'];
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
        if (($event['status'] ?? '') === 'deleted' && $role !== 'superadmin') {
            return false;
        }
        return $isStaffRole($role) || ($role === 'organizer' && $userId !== '' && $event['organizerId'] === $userId);
    };
    $canManageEvent = static function (?array $event, array $auth) use ($isStaffRole): bool {
        if (!$event) {
            return false;
        }
        $role = (string) ($auth['role'] ?? '');
        $userId = (string) ($auth['sub'] ?? '');
        if (($event['status'] ?? '') === 'deleted' && $role !== 'superadmin') {
            return false;
        }
        return $isStaffRole($role) || ($role === 'organizer' && $userId !== '' && $event['organizerId'] === $userId);
    };
    $touchesAdminAccount = static function (?array $existing, array $payload): bool {
        $existingRole = (string) ($existing['role'] ?? '');
        $nextRole = array_key_exists('role', $payload) ? (string) $payload['role'] : '';
        return in_array($existingRole, ['admin', 'superadmin'], true) || in_array($nextRole, ['admin', 'superadmin'], true);
    };

    $publicUploadBase = '/' . trim((string) Env::get('APP_UPLOAD_PUBLIC_BASE', '/uploads'), '/');
    if (!str_starts_with($publicUploadBase, '/api/v1/')) {
        $app->get($publicUploadBase . '/{path:.+}', function (Request $request, Response $response, array $args) use ($uploads) {
            $path = $uploads->resolvePublicPath((string) ($args['path'] ?? ''));
            if ($path === null) {
                return JsonResponder::error($response, 'not_found', 'File not found', 404);
            }

            $mimeType = mime_content_type($path) ?: 'application/octet-stream';
            $response->getBody()->write((string) file_get_contents($path));
            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
        });
    }

    $app->group('/api/v1', function (RouteCollectorProxy $api) use ($jwt, $users, $events, $registrations, $results, $series, $shop, $challenges, $pages, $partners, $admin, $uploads, $body, $query, $adminRoles, $staffRoles, $eventManagerRoles, $publisherRoles, $optionalAuth, $isStaffRole, $canViewEvent, $canManageEvent, $isSuperAdmin, $touchesAdminAccount): void {
        $api->get('/health', static fn (Request $request, Response $response) => JsonResponder::ok($response, ['status' => 'ok']));

        $api->post('/uploads/event-images', function (Request $request, Response $response) use ($uploads) {
            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                return JsonResponder::error($response, 'validation_error', 'Image file is required', 422);
            }

            try {
                $result = $uploads->storeImage($file, 'events');
            } catch (RuntimeException $e) {
                $messages = [
                    'file_too_large' => 'Uploaded image is too large',
                    'unsupported_file_type' => 'Only JPG, PNG and WebP images are supported',
                    'upload_dir_unavailable' => 'Upload directory is unavailable',
                    'upload_failed' => 'File upload failed',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Upload failed', 422);
            }

            return JsonResponder::ok($response, $result, 'Image uploaded', 201);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->get('/uploads/{path:.+}', function (Request $request, Response $response, array $args) use ($uploads) {
            $path = $uploads->resolvePublicPath((string) ($args['path'] ?? ''));
            if ($path === null) {
                return JsonResponder::error($response, 'not_found', 'File not found', 404);
            }

            $mimeType = mime_content_type($path) ?: 'application/octet-stream';
            $response->getBody()->write((string) file_get_contents($path));
            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
        });

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
            unset($payload['eventLimit']);
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
            $role = (string) ($auth['role'] ?? '');
            if (!empty($filters['includeUnpublished']) && $isStaffRole($role)) {
                $filters['includeUnpublished'] = true;
                $filters['includePrivate'] = true;
                $filters['includeDeleted'] = $role === 'superadmin' && !empty($filters['includeDeleted']);
            } else {
                unset($filters['includeUnpublished']);
                unset($filters['includeDeleted']);
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
            if (!$isStaffRole($role)) {
                unset($payload['featured']);
            }
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
                if ($e->getMessage() === 'event_limit_exceeded') {
                    return JsonResponder::error($response, 'event_limit_exceeded', 'Organizer has no available event slots', 409);
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
                unset($payload['organizerId'], $payload['organizerName'], $payload['status'], $payload['featured']);
                if (in_array((string) ($event['status'] ?? ''), ['awaiting_approval', 'approved'], true)) {
                    $payload['status'] = 'draft';
                    $payload['submittedAt'] = null;
                    $payload['approvedAt'] = null;
                    $payload['approvedBy'] = null;
                }
            } elseif (array_key_exists('organizerId', $payload) && ($payload['organizerId'] ?? '') !== ($event['organizerId'] ?? '')) {
                if (($event['status'] ?? '') === 'active') {
                    return JsonResponder::error($response, 'published_organizer_locked', 'Published events cannot be transferred to another organizer', 409);
                }
                $organizer = $users->find((string) $payload['organizerId']);
                if (!$organizer || $organizer['role'] !== 'organizer' || ($organizer['status'] ?? 'active') !== 'active') {
                    return JsonResponder::error($response, 'invalid_organizer', 'Active organizer is required', 422);
                }
                try {
                    $events->transferOrganizer($args['id'], $organizer);
                } catch (RuntimeException $e) {
                    if ($e->getMessage() === 'event_limit_exceeded') {
                        return JsonResponder::error($response, 'event_limit_exceeded', 'Organizer has no available event slots', 409);
                    }
                    throw $e;
                }
                unset($payload['organizerId'], $payload['organizerName']);
            } elseif (!$isStaffRole($role)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            return JsonResponder::ok($response, $events->update($args['id'], $payload));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->delete('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $canManageEvent, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            $role = (string) ($auth['role'] ?? '');
            if (!$event || !$events->canDeleteBeforeRegistrationOpened($event)) {
                return JsonResponder::error($response, 'event_delete_forbidden', 'Only upcoming events without registrations can be deleted. Archive past events instead.', 409);
            }
            $events->delete($args['id']);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/events/{id}/status', function (Request $request, Response $response, array $args) use ($events, $body, $canManageEvent, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            $role = (string) ($auth['role'] ?? '');
            $status = (string) ($body($request)['status'] ?? 'draft');
            if (!$isStaffRole($role) && $status !== 'archived') {
                return JsonResponder::error($response, 'forbidden', 'Organizer cannot manually change event status', 403);
            }
            try {
                $event = $events->updateStatus($args['id'], $status, $body($request)['reason'] ?? null);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'event_archive_before_start') {
                    return JsonResponder::error($response, 'event_archive_before_start', 'Only events that already ended can be archived', 409);
                }
                if ($e->getMessage() === 'invalid_status_transition') {
                    return JsonResponder::error($response, 'invalid_status_transition', 'Use submit, approve, reject and publish actions for moderation workflow', 409);
                }
                throw $e;
            }
            return $event ? JsonResponder::ok($response, $event) : JsonResponder::error($response, 'not_found', 'Event not found', 404);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
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
        })->add(new AuthMiddleware($jwt, ['organizer', 'admin', 'superadmin']));
        $api->get('/organizers/{organizerId}/events', function (Request $request, Response $response, array $args) use ($events, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            if (!$isStaffRole($role) && (($auth['sub'] ?? '') !== $args['organizerId'])) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            return JsonResponder::ok($response, $events->byOrganizer($args['organizerId'], $role === 'superadmin'));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/organizers/{organizerId}/event-limit', function (Request $request, Response $response, array $args) use ($events, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            if (!$isStaffRole($role) && (($auth['sub'] ?? '') !== $args['organizerId'])) {
                return JsonResponder::error($response, 'forbidden', 'Insufficient permissions', 403);
            }
            return JsonResponder::ok($response, $events->eventLimit($args['organizerId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/organizers/{organizerId}/event-limit', function (Request $request, Response $response, array $args) use ($events, $users, $body) {
            $organizer = $users->find($args['organizerId']);
            if (!$organizer || $organizer['role'] !== 'organizer') {
                return JsonResponder::error($response, 'invalid_organizer', 'Organizer is required', 422);
            }
            $payload = $body($request);
            $limit = max(0, (int) ($payload['limit'] ?? 0));
            try {
                return JsonResponder::ok($response, $events->updateEventLimit($args['organizerId'], $limit), 'Event limit updated');
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'event_limit_below_usage') {
                    return JsonResponder::error($response, 'event_limit_below_usage', 'Event limit cannot be lower than organizer current event count', 409);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt, $adminRoles));

        $api->get('/registrations', function (Request $request, Response $response) use ($registrations, $query) {
            $page = $registrations->list($query($request));
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        })->add(new AuthMiddleware($jwt));
        $api->get('/events/{eventId}/registrations', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->byEvent($args['eventId'])))
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/users/{userId}/registrations', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->byUser($args['userId'])))
            ->add(new AuthMiddleware($jwt));
        $api->post('/registrations', function (Request $request, Response $response) use ($registrations, $body) {
            try {
                return JsonResponder::ok($response, $registrations->create($body($request)), 'Registration created', 201);
            } catch (RuntimeException $e) {
                $discountErrors = [
                    'discount_code_invalid' => 'Discount code is invalid.',
                    'discount_code_inactive' => 'Discount code is inactive.',
                    'discount_code_not_started' => 'Discount code is not active yet.',
                    'discount_code_expired' => 'Discount code has expired.',
                    'discount_code_used_up' => 'Discount code usage limit has been reached.',
                    'discount_code_distance_mismatch' => 'Discount code does not apply to the selected distance.',
                    'registration_distance_invalid' => 'Selected distance is invalid.',
                ];
                if (array_key_exists($e->getMessage(), $discountErrors)) {
                    return JsonResponder::error($response, $e->getMessage(), $discountErrors[$e->getMessage()], 422);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt));
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

        $api->get('/series', function (Request $request, Response $response) use ($series, $query, $optionalAuth, $isStaffRole) {
            $filters = $query($request);
            if (!empty($filters['includeArchived']) && $isStaffRole((string) ($optionalAuth($request)['role'] ?? ''))) {
                $filters['includeArchived'] = true;
            } else {
                unset($filters['includeArchived']);
            }
            $page = $series->list($filters);
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        });
        $api->get('/series/slug/{slug}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $series->findBySlug($args['slug'])));
        $api->get('/series/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $series->find($args['id'])));
        $api->post('/series', fn (Request $request, Response $response) => JsonResponder::ok($response, $series->create($body($request), (array) $request->getAttribute('auth')), 'Series created', 201))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->patch('/series/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $series->update($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/series/{id}/archive', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $series->archive($args['id']), 'Series archived'))
            ->add(new AuthMiddleware($jwt, $staffRoles));

        $api->get('/shop/categories', function (Request $request, Response $response) use ($shop, $optionalAuth, $isStaffRole) {
            $filters = $request->getQueryParams();
            $includeArchived = !empty($filters['includeArchived']) && $isStaffRole((string) ($optionalAuth($request)['role'] ?? ''));
            return JsonResponder::ok($response, $shop->categories($includeArchived));
        });
        $api->post('/shop/categories', fn (Request $request, Response $response) => JsonResponder::ok($response, $shop->createCategory($body($request)), 'Category created', 201))
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/shop/categories/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $shop->updateCategory($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->delete('/shop/categories/{id}', function (Request $request, Response $response, array $args) use ($shop) {
            $shop->archiveCategory($args['id']);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $adminRoles));

        $api->get('/shop/delivery-methods', static fn (Request $request, Response $response) => JsonResponder::ok($response, [
            ['id' => 'courier', 'name' => 'Kurier', 'price' => 15.99, 'estimatedDays' => '1-2 dni'],
            ['id' => 'pickup', 'name' => 'Odbior osobisty', 'price' => 0, 'estimatedDays' => 'w dniu zawodow'],
        ]));
        $api->get('/shop/payment-methods', static fn (Request $request, Response $response) => JsonResponder::ok($response, [
            ['id' => 'card', 'name' => 'Karta platnicza'],
            ['id' => 'blik', 'name' => 'BLIK'],
            ['id' => 'bank', 'name' => 'Przelew bankowy'],
        ]));

        $api->get('/shop/products', function (Request $request, Response $response) use ($shop, $query, $optionalAuth, $isStaffRole) {
            $filters = $query($request);
            if (!empty($filters['includeArchived']) && $isStaffRole((string) ($optionalAuth($request)['role'] ?? ''))) {
                $filters['includeArchived'] = true;
            } else {
                unset($filters['includeArchived']);
            }
            $page = $shop->products($filters);
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        });
        $api->get('/shop/products/slug/{slug}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $shop->findProductBySlug($args['slug'])));
        $api->get('/shop/products/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $shop->findProduct($args['id'])));
        $api->post('/shop/products', fn (Request $request, Response $response) => JsonResponder::ok($response, $shop->createProduct($body($request)), 'Product created', 201))
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/shop/products/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $shop->updateProduct($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->delete('/shop/products/{id}', function (Request $request, Response $response, array $args) use ($shop) {
            $shop->archiveProduct($args['id']);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $adminRoles));

        $api->get('/challenges', function (Request $request, Response $response) use ($challenges, $query, $optionalAuth, $isStaffRole) {
            $filters = $query($request);
            if (!empty($filters['includeArchived']) && $isStaffRole((string) ($optionalAuth($request)['role'] ?? ''))) {
                $filters['includeArchived'] = true;
            } else {
                unset($filters['includeArchived']);
            }
            return JsonResponder::ok($response, $challenges->list($filters));
        });
        $api->get('/challenges/slug/{slug}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $challenges->findBySlug($args['slug'])));
        $api->get('/challenges/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $challenges->find($args['id'])));
        $api->post('/challenges', function (Request $request, Response $response) use ($challenges, $body) {
            $auth = (array) $request->getAttribute('auth');
            return JsonResponder::ok($response, $challenges->create($body($request), (string) ($auth['sub'] ?? '')), 'Challenge created', 201);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->patch('/challenges/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $challenges->update($args['id'], $body($request))))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/challenges/{id}/archive', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $challenges->archive($args['id']), 'Challenge archived'))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->get('/challenge-participations', fn (Request $request, Response $response) => JsonResponder::ok($response, $challenges->participations($request->getQueryParams()['challengeId'] ?? null, $request->getQueryParams()['userId'] ?? null)))
            ->add(new AuthMiddleware($jwt));
        $api->post('/challenges/{id}/join', function (Request $request, Response $response, array $args) use ($challenges, $users) {
            $auth = (array) $request->getAttribute('auth');
            $user = $users->find((string) ($auth['sub'] ?? ''));
            if (!$user) {
                return JsonResponder::error($response, 'not_found', 'User not found', 404);
            }
            return JsonResponder::ok($response, $challenges->join($args['id'], $user), 'Challenge joined', 201);
        })->add(new AuthMiddleware($jwt));
        $api->post('/challenge-entries', fn (Request $request, Response $response) => JsonResponder::ok($response, $challenges->submitEntry($body($request), (array) $request->getAttribute('auth')), 'Challenge entry submitted', 201))
            ->add(new AuthMiddleware($jwt));
        $api->patch('/challenge-entries/{id}/review', function (Request $request, Response $response, array $args) use ($challenges, $body) {
            $auth = (array) $request->getAttribute('auth');
            $payload = $body($request);
            return JsonResponder::ok($response, $challenges->reviewEntry($args['id'], (string) ($payload['status'] ?? 'pending'), $payload['reason'] ?? null, (string) ($auth['sub'] ?? '')));
        })->add(new AuthMiddleware($jwt, $staffRoles));

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
