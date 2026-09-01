<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use ZmierzymyCzas\Database\SeedManifest;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Cms\InfoPageRepository;
use ZmierzymyCzas\Domain\Cms\PartnerRepository;
use ZmierzymyCzas\Domain\Events\EventBackgroundRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Payments\Http\CurlHttpClient;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowConfig;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowGateway;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowNotification;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowNotificationVerifier;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayConfig;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayGateway;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayNotification;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayNotificationVerifier;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayOAuthClient;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultImportRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Results\VirtualResultRepository;
use ZmierzymyCzas\Domain\Series\SeriesRepository;
use ZmierzymyCzas\Domain\Certificate\CertificateRepository;
use ZmierzymyCzas\Domain\Insurance\InsuranceRepository;
use ZmierzymyCzas\Domain\Shop\OrderRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Http\Middleware\AuthMiddleware;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\JsonResponder;
use ZmierzymyCzas\Support\UploadedFileStorage;

return static function (App $app, PDO $pdo, JwtService $jwt): void {
    $users = new UserRepository($pdo);
    $events = new EventRepository($pdo);
    // E1.6: trasa A (bramka) is only ever wired up with a real gateway when this
    // deployment actually has Tpay credentials configured (production, or a real
    // sandbox account) — everywhere else (local dev, CI, this repo's own test
    // suite, none of which set TPAY_CLIENT_ID/SECRET) RegistrationRepository gets
    // no gateway at all and simply skips starting a payment session, exactly as it
    // behaved before this ticket. See TpayConfig::isConfigured().
    $tpayConfig = TpayConfig::fromEnv();
    $tpayHttp = new CurlHttpClient();
    $tpayGateway = $tpayConfig->isConfigured()
        ? new TpayGateway($tpayHttp, $tpayConfig, new TpayOAuthClient($tpayHttp, $tpayConfig))
        : null;
    $tpayNotificationVerifier = new TpayNotificationVerifier($tpayHttp, $tpayConfig);
    // E1.7: trasa A's second gateway, PayNow — same "only wired up with real
    // credentials configured" stance as Tpay above (see PayNowConfig::isConfigured()).
    // Which of the two an event actually uses is resolved per-Wydarzenie by
    // RegistrationRepository (payment_config.provider), not here.
    $payNowConfig = PayNowConfig::fromEnv();
    $payNowHttp = new CurlHttpClient();
    $payNowGateway = $payNowConfig->isConfigured() ? new PayNowGateway($payNowHttp, $payNowConfig) : null;
    $payNowNotificationVerifier = new PayNowNotificationVerifier($payNowConfig);
    $registrations = new RegistrationRepository($pdo, $tpayGateway, $payNowGateway);
    $results = new ResultRepository($pdo);
    $resultImports = new ResultImportRepository($pdo);
    $virtualResults = new VirtualResultRepository($pdo);
    $series = new SeriesRepository($pdo);
    $shop = new ShopRepository($pdo);
    $challenges = new ChallengeRepository($pdo);
    $pages = new InfoPageRepository($pdo);
    $partners = new PartnerRepository($pdo);
    $admin = new AdminRepository($pdo);
    $backgrounds = new EventBackgroundRepository($pdo);
    $insurance = new InsuranceRepository($pdo);
    $orders = new OrderRepository($pdo);
    $certificates = new CertificateRepository($pdo);
    $competitors = new CompetitorRepository($pdo);
    $care = new CareRepository($pdo);
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
    $publicEventStatuses = ['published', 'finished'];
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
    // E1.1 permission matrix. This codebase has no Event Team membership model yet (see
    // E0.8's commit note: activity-log org-scoping was deliberately kept single-owner,
    // "bez modelu zespołu — poza zakresem tego ticketu") — "Event Team" in the spec is
    // therefore the event's single owning organizer account until that model exists.
    // "Organizer (outside Team)" is any other organizer account.
    $canViewEvent = static function (?array $event, array $auth, ?string $accessCode = null) use ($isStaffRole): bool {
        if (!$event) {
            return false;
        }
        $role = (string) ($auth['role'] ?? '');
        $userId = (string) ($auth['sub'] ?? '');
        $isOwner = $role === 'organizer' && $userId !== '' && $event['organizerId'] === $userId;

        if (($event['deletedAt'] ?? null) !== null) {
            return $role === 'superadmin';
        }

        $status = (string) ($event['publicationStatus'] ?? '');
        if (in_array($status, ['published', 'finished', 'cancelled'], true)) {
            if ($isOwner || $isStaffRole($role)) {
                return true;
            }
            if (empty($event['isPrivate'])) {
                return true; // public — visible to everyone, including guests
            }
            // Private: only via direct link (this event's id/slug), gated by the access
            // code when the organizer set one. "Organizer (outside Team)" gets no access
            // here at all, per the matrix — only guests/participants get the link route.
            if ($role === 'organizer') {
                return false;
            }
            $requiredCode = (string) ($event['accessCode'] ?? '');
            return $requiredCode === '' || $accessCode === $requiredCode;
        }

        // Draft / Submitted: never public. Draft is Event Team (owner) only, plus
        // admin/superadmin read-only oversight — moderator gets none on Draft per the
        // matrix. Submitted additionally opens up to the moderator for approve/reject.
        if ($isOwner) {
            return true;
        }
        if ($status === 'submitted') {
            return $isStaffRole($role);
        }
        return in_array($role, ['admin', 'superadmin'], true);
    };
    $canManageEvent = static function (?array $event, array $auth): bool {
        if (!$event) {
            return false;
        }
        $role = (string) ($auth['role'] ?? '');
        $userId = (string) ($auth['sub'] ?? '');
        if (($event['deletedAt'] ?? null) !== null && $role !== 'superadmin') {
            return false;
        }
        if ($role === 'organizer') {
            return $userId !== '' && $event['organizerId'] === $userId;
        }
        if (!in_array($role, ['admin', 'superadmin', 'moderator'], true)) {
            return false;
        }
        // Staff can edit once an event has cleared moderation (Published/Finished/
        // Cancelled). Draft is Event Team only and Submitted is view + approve/reject —
        // neither is a general staff edit, per the permission matrix.
        return in_array((string) ($event['publicationStatus'] ?? ''), ['published', 'finished', 'cancelled'], true);
    };
    $canUploadResults = static function (?array $event, array $auth) use ($canManageEvent): bool {
        return $canManageEvent($event, $auth)
            && in_array((string) ($event['publicationStatus'] ?? ''), ['published', 'finished'], true);
    };
    $touchesAdminAccount = static function (?array $existing, array $payload): bool {
        $existingRole = (string) ($existing['role'] ?? '');
        $nextRole = array_key_exists('role', $payload) ? (string) $payload['role'] : '';
        return in_array($existingRole, ['admin', 'superadmin'], true) || in_array($nextRole, ['admin', 'superadmin'], true);
    };
    // Server-authored activity log (E0.8): actor identity always comes from the verified
    // JWT (`$auth`), never from the request body, so an entry can't be forged as someone
    // else. `targetName` is a deliberate snapshot of the target's name at the time of the
    // action, not a foreign-key lookup — keep it that way.
    $logActivity = static function (array $auth, string $action, string $description, ?string $targetType = null, ?string $targetId = null, ?string $targetName = null) use ($admin): void {
        $admin->insertActivityLog([
            'userId' => (string) ($auth['sub'] ?? ''),
            'action' => $action,
            'description' => $description,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetName' => $targetName,
        ]);
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

    $app->group('/api/v1', function (RouteCollectorProxy $api) use ($pdo, $jwt, $competitors, $care, $users, $events, $registrations, $results, $resultImports, $virtualResults, $series, $shop, $challenges, $pages, $partners, $admin, $backgrounds, $uploads, $orders, $insurance, $certificates, $body, $query, $adminRoles, $staffRoles, $eventManagerRoles, $publisherRoles, $optionalAuth, $isStaffRole, $canViewEvent, $canManageEvent, $canUploadResults, $isSuperAdmin, $touchesAdminAccount, $logActivity, $tpayNotificationVerifier, $payNowNotificationVerifier): void {
        $api->get('/health', static fn (Request $request, Response $response) => JsonResponder::ok($response, ['status' => 'ok']));

        $api->post('/uploads/avatars', function (Request $request, Response $response) use ($uploads, $users, $jwt) {
            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                return JsonResponder::error($response, 'validation_error', 'Wybierz plik obrazu.', 422);
            }

            try {
                $result = $uploads->storeImage($file, 'avatars');
            } catch (RuntimeException $e) {
                $messages = [
                    'file_too_large' => 'Obraz jest za duży. Maksymalny rozmiar pliku to 5 MB.',
                    'unsupported_file_type' => 'Nieobsługiwany format obrazu. Wgraj plik JPG, PNG albo WebP.',
                    'invalid_image_dimensions' => 'Nie udało się odczytać wymiarów obrazu. Wybierz inny plik.',
                    'image_dimensions_too_large' => 'Obraz ma zbyt dużą rozdzielczość. Zmniejsz go i spróbuj ponownie.',
                    'upload_dir_unavailable' => 'Nie można teraz zapisać pliku na serwerze.',
                    'upload_failed' => 'Nie udało się wgrać pliku. Spróbuj ponownie.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udało się wgrać pliku. Spróbuj ponownie.', 422);
            }

            $auth = (array) $request->getAttribute('auth');
            $userId = (string) ($auth['sub'] ?? '');
            if ($userId !== '') {
                $users->update($userId, ['avatar' => $result['url']]);
            }

            return JsonResponder::ok($response, $result, 'Zdjęcie profilowe zostało wgrane.', 201);
        })->add(new AuthMiddleware($jwt));

        $api->post('/uploads/event-images', function (Request $request, Response $response) use ($uploads) {
            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                return JsonResponder::error($response, 'validation_error', 'Wybierz plik obrazu.', 422);
            }

            try {
                $result = $uploads->storeImage($file, 'events');
            } catch (RuntimeException $e) {
                $messages = [
                    'file_too_large' => 'Obraz jest za duży. Maksymalny rozmiar pliku to 5 MB.',
                    'unsupported_file_type' => 'Nieobsługiwany format obrazu. Wgraj plik JPG, PNG albo WebP.',
                    'invalid_image_dimensions' => 'Nie udało się odczytać wymiarów obrazu. Wybierz inny plik.',
                    'image_dimensions_too_large' => 'Obraz ma zbyt dużą rozdzielczość. Zmniejsz go i spróbuj ponownie.',
                    'upload_dir_unavailable' => 'Nie można teraz zapisać pliku na serwerze.',
                    'upload_failed' => 'Nie udało się wgrać pliku. Spróbuj ponownie.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udało się wgrać pliku. Spróbuj ponownie.', 422);
            }

            return JsonResponder::ok($response, $result, 'Obraz został wgrany.', 201);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->post('/uploads/event-documents', function (Request $request, Response $response) use ($uploads) {
            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                return JsonResponder::error($response, 'validation_error', 'Wybierz plik do wgrania.', 422);
            }

            try {
                $result = $uploads->storeDocument($file, 'event-docs');
            } catch (RuntimeException $e) {
                $messages = [
                    'file_too_large' => 'Plik jest za duży. Maksymalny rozmiar pliku to 5 MB.',
                    'unsupported_file_type' => 'Nieobsługiwany format pliku. Dozwolone są PDF, DOC, DOCX, XLS, XLSX, TXT oraz JPG, PNG i WebP.',
                    'image_dimensions_too_large' => 'Obraz ma zbyt dużą rozdzielczość. Zmniejsz go i spróbuj ponownie.',
                    'upload_dir_unavailable' => 'Nie można teraz zapisać pliku na serwerze.',
                    'upload_failed' => 'Nie udało się wgrać pliku. Spróbuj ponownie.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udało się wgrać pliku. Spróbuj ponownie.', 422);
            }

            return JsonResponder::ok($response, $result, 'Plik został wgrany.', 201);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->post('/uploads/partner-logos', function (Request $request, Response $response) use ($uploads) {
            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                return JsonResponder::error($response, 'validation_error', 'Wybierz plik logo.', 422);
            }

            try {
                $result = $uploads->storeImage($file, 'partners');
            } catch (RuntimeException $e) {
                $messages = [
                    'file_too_large' => 'Logo jest za duże. Maksymalny rozmiar pliku to 5 MB.',
                    'unsupported_file_type' => 'Nieobsługiwany format logo. Wgraj plik JPG, PNG albo WebP.',
                    'invalid_image_dimensions' => 'Nie udało się odczytać wymiarów logo. Wybierz inny plik.',
                    'image_dimensions_too_large' => 'Logo ma zbyt dużą rozdzielczość. Zmniejsz je i spróbuj ponownie.',
                    'upload_dir_unavailable' => 'Nie można teraz zapisać pliku na serwerze.',
                    'upload_failed' => 'Nie udało się wgrać pliku. Spróbuj ponownie.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udało się wgrać pliku. Spróbuj ponownie.', 422);
            }

            return JsonResponder::ok($response, $result, 'Logo zostało wgrane.', 201);
        })->add(new AuthMiddleware($jwt, $adminRoles));

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
        $api->post('/users', function (Request $request, Response $response) use ($users, $body, $isSuperAdmin, $logActivity) {
            $payload = $body($request);
            if (in_array((string) ($payload['role'] ?? ''), ['admin', 'superadmin'], true) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can create admin accounts', 403);
            }
            $created = $users->create($payload);
            $logActivity((array) $request->getAttribute('auth'), 'user_created', 'Utworzono konto użytkownika', 'user', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'User created', 201);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/users/{id}', function (Request $request, Response $response, array $args) use ($users, $body, $isSuperAdmin, $touchesAdminAccount, $logActivity) {
            $payload = $body($request);
            unset($payload['eventLimit']);
            $existing = $users->find($args['id']);
            if ($touchesAdminAccount($existing, $payload) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can modify admin accounts', 403);
            }
            $updated = $users->update($args['id'], $payload);
            $logActivity((array) $request->getAttribute('auth'), 'user_updated', 'Zaktualizowano konto użytkownika', 'user', (string) $args['id'], (string) ($updated['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt));
        $api->delete('/users/{id}', function (Request $request, Response $response, array $args) use ($users, $isSuperAdmin, $touchesAdminAccount, $logActivity) {
            $existing = $users->find($args['id']);
            if ($touchesAdminAccount($existing, []) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can delete admin accounts', 403);
            }
            $users->delete($args['id']);
            $logActivity((array) $request->getAttribute('auth'), 'user_deleted', 'Usunięto konto użytkownika', 'user', (string) $args['id'], (string) ($existing['name'] ?? ''));
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/users/{id}/role', function (Request $request, Response $response, array $args) use ($users, $body, $isSuperAdmin, $touchesAdminAccount, $logActivity) {
            $payload = ['role' => $body($request)['role'] ?? null];
            if ($touchesAdminAccount($users->find($args['id']), $payload) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can modify admin roles', 403);
            }
            $updated = $users->update($args['id'], $payload);
            $logActivity((array) $request->getAttribute('auth'), 'user_role_changed', 'Zmieniono rolę użytkownika na ' . (string) ($payload['role'] ?? ''), 'user', (string) $args['id'], (string) ($updated['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/users/{id}/status', function (Request $request, Response $response, array $args) use ($users, $body, $isSuperAdmin, $touchesAdminAccount, $logActivity) {
            if ($touchesAdminAccount($users->find($args['id']), []) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can change admin status', 403);
            }
            $status = $body($request)['status'] ?? null;
            $updated = $users->update($args['id'], ['status' => $status]);
            $logActivity((array) $request->getAttribute('auth'), 'user_status_changed', 'Zmieniono status użytkownika na ' . (string) $status, 'user', (string) $args['id'], (string) ($updated['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
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
            $accessCode = $request->getQueryParams()['accessCode'] ?? null;
            if (!$canViewEvent($event, $optionalAuth($request), $accessCode !== null ? (string) $accessCode : null)) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }
            return JsonResponder::ok($response, $event);
        });
        $api->get('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $optionalAuth, $canViewEvent) {
            $event = $events->find($args['id']);
            $accessCode = $request->getQueryParams()['accessCode'] ?? null;
            if (!$canViewEvent($event, $optionalAuth($request), $accessCode !== null ? (string) $accessCode : null)) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }
            return JsonResponder::ok($response, $event);
        });
        $api->post('/events', function (Request $request, Response $response) use ($events, $users, $body, $isStaffRole, $logActivity) {
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
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            try {
                $created = $events->createForOrganizer($payload, $organizer, (string) ($auth['sub'] ?? ''));
                $logActivity($auth, 'event_created', 'Utworzono wydarzenie', 'event', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
                return JsonResponder::ok($response, $created, 'Event created', 201);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'event_limit_exceeded') {
                    return JsonResponder::error($response, 'event_limit_exceeded', 'Organizer has no available event slots', 409);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $users, $body, $canManageEvent, $isStaffRole, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $payload = $body($request);
            $role = (string) ($auth['role'] ?? '');
            // The publication workflow axis and is_test only ever move through their own
            // dedicated endpoints (submit/approve/reject/cancel/visibility/is-test) — a
            // generic edit can never bypass moderation or the state machine by smuggling
            // these in the body (E1.1).
            unset($payload['status'], $payload['publicationStatus'], $payload['isTest']);
            if ($role === 'organizer') {
                unset($payload['organizerId'], $payload['organizerName'], $payload['featured']);
                if ((string) ($event['publicationStatus'] ?? '') === 'submitted') {
                    // Editing a submission-in-review invalidates that pending review — the
                    // organizer has to resubmit the edited version. Published events are
                    // exempt (E1.1: moderation is a one-time gate, not continuous control).
                    $payload['publicationStatus'] = 'draft';
                    $payload['submittedAt'] = null;
                    $payload['approvedAt'] = null;
                    $payload['approvedBy'] = null;
                }
            } elseif (array_key_exists('organizerId', $payload) && ($payload['organizerId'] ?? '') !== ($event['organizerId'] ?? '')) {
                if (in_array((string) ($event['publicationStatus'] ?? ''), ['published', 'finished'], true)) {
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
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $updated = $events->update($args['id'], $payload);
            $logActivity($auth, 'event_updated', 'Zaktualizowano wydarzenie', 'event', (string) $args['id'], (string) ($updated['name'] ?? $event['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->delete('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $canManageEvent, $isStaffRole, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $role = (string) ($auth['role'] ?? '');
            if (!$event || !$events->canDeleteBeforeRegistrationOpened($event)) {
                return JsonResponder::error($response, 'event_delete_forbidden', 'Only upcoming events without registrations can be deleted.', 409);
            }
            $events->delete($args['id']);
            $logActivity($auth, 'event_deleted', 'Usunięto wydarzenie', 'event', (string) $args['id'], (string) ($event['name'] ?? ''));
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        // E1.1: the old free-form PATCH /events/{id}/status (draft/past/archived) is gone.
        // `past`/`archived` are now the automatic lazy Published→Finished check
        // (EventRepository::lazilyFinishPastEvents(), run on every read); every remaining
        // transition has its own endpoint below, each enforcing the E1.1 permission
        // matrix. EventRepository::updateStatus() is kept only as a repository-level
        // guard against writing publication_status directly — always rejects.
        $api->post('/events/{id}/submit', function (Request $request, Response $response, array $args) use ($events, $canManageEvent, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            if (!$canManageEvent($events->find($args['id']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $event = $events->submit($args['id']);
            if (!$event) {
                return JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be submitted in its current status', 409);
            }
            $logActivity($auth, 'event_submitted', 'Przesłano wydarzenie do moderacji', 'event', (string) $args['id'], (string) ($event['name'] ?? ''));
            return JsonResponder::ok($response, $event);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->post('/events/{id}/approve', function (Request $request, Response $response, array $args) use ($events, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            // Approve is a one-step Submitted -> Published (E1.1) — there is no separate
            // "approved" resting state to publish from afterwards.
            $event = $events->approve($args['id'], (string) ($auth['sub'] ?? ''));
            if (!$event) {
                return JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be approved in its current status', 409);
            }
            $logActivity($auth, 'event_approved', 'Zatwierdzono i opublikowano wydarzenie', 'event', (string) $args['id'], (string) ($event['name'] ?? ''));
            return JsonResponder::ok($response, $event);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/events/{id}/reject', function (Request $request, Response $response, array $args) use ($events, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $reason = trim((string) ($body($request)['reason'] ?? ''));
            if ($reason === '') {
                return JsonResponder::error($response, 'validation_error', 'Rejection reason is required', 422);
            }
            $event = $events->reject($args['id'], $reason);
            if (!$event) {
                return JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be rejected in its current status', 409);
            }
            $logActivity($auth, 'event_rejected', 'Odrzucono wydarzenie: ' . $reason, 'event', (string) $args['id'], (string) ($event['name'] ?? ''));
            return JsonResponder::ok($response, $event);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        // Published -> Cancelled. Manual, terminal, Owner or admin only per the matrix
        // (deliberately narrower than $eventManagerRoles — no moderator, no Event Team
        // other than the owner). Bulk refunds are ticket 13, out of scope here.
        $api->post('/events/{id}/cancel', function (Request $request, Response $response, array $args) use ($events, $registrations, $adminRoles, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            $role = (string) ($auth['role'] ?? '');
            $isOwner = $role === 'organizer' && ($event['organizerId'] ?? '') === ($auth['sub'] ?? '');
            if (!$isOwner && !in_array($role, $adminRoles, true)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $cancelled = $events->cancel($args['id']);
            if (!$cancelled) {
                return JsonResponder::error($response, 'invalid_status_transition', 'Only a published event can be cancelled', 409);
            }
            // E1.5, spec 13 rozstrzygnięcie 2: odwołanie Wydarzenia to zbiorcza operacja —
            // wszystkie opłacone Zapisy trafiają w jednej akcji do „do zwrotu", nie N
            // osobnych żądań zawodnika.
            $registrations->bulkApproveRefundsForEvent((string) $args['id'], (string) ($auth['sub'] ?? ''));
            $logActivity($auth, 'event_cancelled', 'Odwołano wydarzenie', 'event', (string) $args['id'], (string) ($cancelled['name'] ?? ''));
            return JsonResponder::ok($response, $cancelled);
        })->add(new AuthMiddleware($jwt, ['organizer', 'admin', 'superadmin']));
        // Visibility toggle (public/private), only valid once Published — organizer/team,
        // moderator or admin, per the matrix.
        $api->patch('/events/{id}/visibility', function (Request $request, Response $response, array $args) use ($events, $body, $canManageEvent, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $visibility = (string) ($body($request)['visibility'] ?? '');
            if (!in_array($visibility, ['public', 'private'], true)) {
                return JsonResponder::error($response, 'validation_error', 'visibility must be "public" or "private"', 422);
            }
            $updated = $events->setVisibility($args['id'], $visibility);
            if (!$updated) {
                return JsonResponder::error($response, 'invalid_status_transition', 'Visibility can only be changed on a published event', 409);
            }
            $logActivity($auth, 'event_visibility_changed', 'Zmieniono widoczność wydarzenia na ' . $visibility, 'event', (string) $args['id'], (string) ($updated['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        // is_test only settable while Draft, organizer/team only per the matrix (staff
        // roles pass the middleware but canManageEvent still gates a Draft event to its
        // owner, since staff never manage Draft — see canManageEvent above).
        $api->patch('/events/{id}/is-test', function (Request $request, Response $response, array $args) use ($events, $body, $canManageEvent, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $isTest = !empty($body($request)['isTest']);
            $updated = $events->setIsTest($args['id'], $isTest);
            if (!$updated) {
                return JsonResponder::error($response, 'invalid_status_transition', 'is_test can only be changed while the event is a draft', 409);
            }
            $logActivity($auth, 'event_test_mode_changed', $isTest ? 'Włączono tryb testowy wydarzenia' : 'Wyłączono tryb testowy wydarzenia', 'event', (string) $args['id'], (string) ($updated['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/organizers/{organizerId}/events', function (Request $request, Response $response, array $args) use ($events, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            if (!$isStaffRole($role) && (($auth['sub'] ?? '') !== $args['organizerId'])) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            return JsonResponder::ok($response, $events->byOrganizer($args['organizerId'], $role === 'superadmin'));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/organizers/{organizerId}/event-limit', function (Request $request, Response $response, array $args) use ($events, $isStaffRole) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            if (!$isStaffRole($role) && (($auth['sub'] ?? '') !== $args['organizerId'])) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            return JsonResponder::ok($response, $events->eventLimit($args['organizerId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/organizers/{organizerId}/event-limit', function (Request $request, Response $response, array $args) use ($events, $users, $body, $logActivity) {
            $organizer = $users->find($args['organizerId']);
            if (!$organizer || $organizer['role'] !== 'organizer') {
                return JsonResponder::error($response, 'invalid_organizer', 'Organizer is required', 422);
            }
            $payload = $body($request);
            $limit = max(0, (int) ($payload['limit'] ?? 0));
            try {
                $updated = $events->updateEventLimit($args['organizerId'], $limit);
                $auth = (array) $request->getAttribute('auth');
                $logActivity($auth, 'organizer_event_limit_changed', 'Zmieniono limit wydarzeń organizatora na ' . $limit, 'user', (string) $args['organizerId'], (string) ($organizer['name'] ?? ''));
                return JsonResponder::ok($response, $updated, 'Event limit updated');
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
        $api->get('/events/{eventId}/start-list', function (Request $request, Response $response, array $args) use ($events, $registrations, $optionalAuth, $canViewEvent) {
            $event = $events->find($args['eventId']);
            if (!$canViewEvent($event, $optionalAuth($request))) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }
            $displayOptions = $event['displayOptions'] ?? [];
            $showAll = (($displayOptions['showParticipants'] ?? null) === 'all')
                || (isset($displayOptions['hideUnpaid']) && !(bool) $displayOptions['hideUnpaid'] && !isset($displayOptions['showParticipants']));
            return JsonResponder::ok($response, $registrations->publicStartList($args['eventId'], $showAll));
        });
        $api->get('/users/{userId}/registrations', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->byUser($args['userId'])))
            ->add(new AuthMiddleware($jwt));
        $api->post('/registrations', function (Request $request, Response $response) use ($registrations, $competitors, $care, $body) {
            $auth = (array) $request->getAttribute('auth');
            $callerId = (string) ($auth['sub'] ?? '');
            $data = $body($request);

            // Zawodnik (competitorId) jest źródłem tożsamości zapisu. Jeśli caller
            // zapisuje kogoś innego niż siebie — musi mieć nad nim Opiekę.
            $competitorId = (string) ($data['competitorId'] ?? '');
            if ($competitorId === '') {
                // Kompatybilność wsteczna: klient wysyłający tylko userId (siebie).
                $targetUserId = (string) ($data['userId'] ?? '');
                $selfCompetitor = $targetUserId !== '' ? $competitors->findByUserId($targetUserId) : null;
                $competitorId = $selfCompetitor['id'] ?? '';
            }

            if ($competitorId !== '' && !$care->canRegister($callerId, $competitorId)) {
                return JsonResponder::error(
                    $response,
                    'not_authorized_to_register',
                    'Nie masz upoważnienia (Opieki) do zapisania tego zawodnika.',
                    403
                );
            }

            if ($competitorId !== '') {
                $data['competitorId'] = $competitorId;
                $competitor = $competitors->find($competitorId);
                if ($competitor !== null) {
                    // registrations.user_id jest NOT NULL i historycznie oznaczał "czyje to konto";
                    // Zawodnik bez Konta (np. dziecko) nie ma tu czego wstawić, więc zapisujemy
                    // Konto opiekuna. Prawdziwą tożsamością zapisu jest competitor_id (Z2).
                    $data['userId'] = $competitor['userId'] ?? $callerId;
                    $data['userName'] = $data['userName'] ?? trim($competitor['firstName'] . ' ' . $competitor['lastName']);
                    $data['userEmail'] = $data['userEmail'] ?? ($competitor['email'] ?? '');
                    if ((string) ($competitor['userId'] ?? '') !== $callerId) {
                        $data['registeredById'] = $callerId;
                    }
                }
            }

            try {
                return JsonResponder::ok($response, $registrations->create($data), 'Registration created', 201);
            } catch (RuntimeException $e) {
                // E1.3: the Dystans is full — a state conflict (someone else took the
                // last slot), not a validation problem with this request, so 409
                // rather than the 422s below.
                if ($e->getMessage() === 'registration_capacity_exceeded') {
                    return JsonResponder::error($response, 'registration_capacity_exceeded', 'This distance has reached its participant limit.', 409);
                }
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

        // E1.5: Maszyna stanów płatności — zwroty. Zawodnik (właściciel Zapisu, albo
        // kto go zapisał — registeredById) może tylko poprosić; organizator (własne
        // Wydarzenie) lub admin/moderator zatwierdza i wykonuje (trasa B, ręcznie —
        // trasa A/bramka to E1.6/E1.7, zob. RegistrationRepository::initiateGatewayRefund()).
        $isRegistrationOwner = static function (?array $registration, array $auth): bool {
            if (!$registration) {
                return false;
            }
            $callerId = (string) ($auth['sub'] ?? '');
            return $callerId !== '' && (
                (string) $registration['userId'] === $callerId
                || (string) ($registration['registeredById'] ?? '') === $callerId
            );
        };
        $api->post('/registrations/{id}/refund-request', function (Request $request, Response $response, array $args) use ($registrations, $events, $canManageEvent, $isRegistrationOwner, $body) {
            $auth = (array) $request->getAttribute('auth');
            $registration = $registrations->find($args['id']);
            if (!$registration) {
                return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
            }
            if (!$isRegistrationOwner($registration, $auth) && !$canManageEvent($events->find((string) $registration['eventId']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $reason = trim((string) ($body($request)['reason'] ?? ''));
            try {
                return JsonResponder::ok($response, $registrations->requestRefund($args['id'], (string) ($auth['sub'] ?? ''), $reason !== '' ? $reason : null));
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'refund_requires_paid_registration') {
                    return JsonResponder::error($response, 'refund_requires_paid_registration', 'Only a paid registration can request a refund.', 409);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt));
        $api->post('/registrations/{id}/refund-approve', function (Request $request, Response $response, array $args) use ($registrations, $events, $canManageEvent, $body) {
            $auth = (array) $request->getAttribute('auth');
            $registration = $registrations->find($args['id']);
            if (!$registration) {
                return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
            }
            if (!$canManageEvent($events->find((string) $registration['eventId']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $payload = $body($request);
            $amount = isset($payload['amount']) && $payload['amount'] !== '' && $payload['amount'] !== null ? (float) $payload['amount'] : null;
            try {
                return JsonResponder::ok($response, $registrations->approveRefund($args['id'], (string) ($auth['sub'] ?? ''), $amount));
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'refund_request_not_found') {
                    return JsonResponder::error($response, 'refund_request_not_found', 'No refund request pending for this registration.', 409);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->post('/registrations/{id}/refund-complete', function (Request $request, Response $response, array $args) use ($registrations, $events, $canManageEvent) {
            $auth = (array) $request->getAttribute('auth');
            $registration = $registrations->find($args['id']);
            if (!$registration) {
                return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
            }
            if (!$canManageEvent($events->find((string) $registration['eventId']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            try {
                return JsonResponder::ok($response, $registrations->markRefunded($args['id'], (string) ($auth['sub'] ?? '')));
            } catch (RuntimeException $e) {
                $errors = [
                    'refund_request_not_found' => 'No refund cycle found for this registration.',
                    'refund_not_approved' => 'The refund must be approved before it can be marked as completed.',
                ];
                if (isset($errors[$e->getMessage()])) {
                    return JsonResponder::error($response, $e->getMessage(), $errors[$e->getMessage()], 409);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->post('/registrations/{id}/retry-payment', function (Request $request, Response $response, array $args) use ($registrations, $events, $canManageEvent, $isRegistrationOwner) {
            $auth = (array) $request->getAttribute('auth');
            $registration = $registrations->find($args['id']);
            if (!$registration) {
                return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
            }
            if (!$isRegistrationOwner($registration, $auth) && !$canManageEvent($events->find((string) $registration['eventId']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            try {
                return JsonResponder::ok($response, $registrations->retryPayment($args['id']));
            } catch (RuntimeException $e) {
                $errors = [
                    'retry_requires_expired_registration' => ['retry_requires_expired_registration', 'Only an expired registration can be retried.', 409],
                    'registration_capacity_exceeded' => ['registration_capacity_exceeded', 'This distance has reached its participant limit.', 409],
                    'registration_distance_invalid' => ['registration_distance_invalid', 'Selected distance is invalid.', 422],
                ];
                if (isset($errors[$e->getMessage()])) {
                    [$code, $message, $status] = $errors[$e->getMessage()];
                    return JsonResponder::error($response, $code, $message, $status);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt));

        // E1.8: trasa B "oznacz jako opłacone" — organizer (własne Wydarzenie)
        // lub admin/moderator ręcznie potwierdza wpłatę na konto bankowe. Nigdy
        // zawodnik (brak $isRegistrationOwner tutaj, celowo, w odróżnieniu od
        // refund-request/retry-payment powyżej) — to organizator/admin widzi
        // wyciąg bankowy, nie zawodnik. Sama tranzycja pending -> confirmed i
        // walidacja "czy to w ogóle trasa B" żyje w
        // RegistrationRepository::markManualPaymentPaid() (reużywa
        // markGatewayPaymentPaid(), tej samej ścieżki co webhook trasy A).
        $api->post('/registrations/{id}/mark-paid', function (Request $request, Response $response, array $args) use ($registrations, $events, $canManageEvent) {
            $auth = (array) $request->getAttribute('auth');
            $registration = $registrations->find($args['id']);
            if (!$registration) {
                return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
            }
            if (!$canManageEvent($events->find((string) $registration['eventId']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            try {
                return JsonResponder::ok($response, $registrations->markManualPaymentPaid($args['id']));
            } catch (RuntimeException $e) {
                $errors = [
                    'registration_not_manual_route' => ['registration_not_manual_route', 'Ten Zapis nie jest na trasie przelewu tradycyjnego.', 409],
                    'registration_not_pending' => ['registration_not_pending', 'Tylko oczekujący Zapis można oznaczyć jako opłacony.', 409],
                    'registration_not_found' => ['not_found', 'Registration not found', 404],
                ];
                if (isset($errors[$e->getMessage()])) {
                    [$code, $message, $status] = $errors[$e->getMessage()];
                    return JsonResponder::error($response, $code, $message, $status);
                }
                throw $e;
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        // E1.6: Tpay webhook. Never behind AuthMiddleware — Tpay's servers call
        // this, not a logged-in user, so there is no JWT to check; the signature
        // verification below is what stands in for authentication here. Every
        // step (extract raw body -> verify X-JWS-Signature -> parse -> apply
        // idempotently by transaction_id) matches the ticket's "nigdy nie ufaj
        // nagłówkom/body bez weryfikacji" and "idempotencja po transaction_id".
        $api->post('/webhooks/tpay', function (Request $request, Response $response) use ($registrations, $tpayNotificationVerifier) {
            $bodyStream = $request->getBody();
            $bodyStream->rewind();
            $rawBody = $bodyStream->getContents();
            $signature = $request->getHeaderLine('X-JWS-Signature');

            if ($signature === '' || !$tpayNotificationVerifier->verify($rawBody, $signature)) {
                return JsonResponder::error($response, 'invalid_signature', 'Invalid or missing webhook signature.', 400);
            }

            try {
                $notification = TpayNotification::fromRawBody($rawBody, $request->getHeaderLine('Content-Type'));
            } catch (RuntimeException) {
                return JsonResponder::error($response, 'invalid_payload', 'Malformed webhook payload.', 400);
            }

            try {
                $result = $registrations->applyGatewayNotification(
                    $notification->transactionId,
                    $notification->paid,
                    $notification->amount
                );
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'gateway_transaction_unknown') {
                    // Not this platform's transaction (or a stale/replayed id) — 404,
                    // not a 500, and deliberately not written to the activity log
                    // (E0.8's log is for actions by an authenticated actor; an
                    // unrecognized webhook call isn't one).
                    return JsonResponder::error($response, 'gateway_transaction_unknown', 'Unknown transaction.', 404);
                }
                throw $e;
            }

            // E1.10 seam (mail — out of scope here per the ticket): a confirmation
            // email would be queued right here, gated on
            // `$result['applied'] && $notification->paid`, i.e. only the one
            // delivery of this notification that actually flipped the Zapis to
            // `confirmed` — never a retried/idempotent no-op delivery.

            $response->getBody()->write('TRUE');
            return $response->withHeader('Content-Type', 'text/plain')->withStatus(200);
        });

        // E1.7: PayNow webhook. Never behind AuthMiddleware — PayNow's servers
        // call this, not a logged-in user, so there is no JWT to check; the
        // signature verification below is what stands in for authentication
        // here. Every step (extract raw body -> verify Signature header ->
        // parse -> apply idempotently by transaction_id) matches the ticket's
        // "nigdy nie ufaj nagłówkom/body bez weryfikacji" and "idempotencja po
        // transaction_id" — same shape as the Tpay webhook above, just against
        // PayNow's own (simpler, HMAC-only) signature scheme.
        $api->post('/webhooks/paynow', function (Request $request, Response $response) use ($registrations, $payNowNotificationVerifier) {
            $bodyStream = $request->getBody();
            $bodyStream->rewind();
            $rawBody = $bodyStream->getContents();
            $signature = $request->getHeaderLine('Signature');

            if ($signature === '' || !$payNowNotificationVerifier->verify($rawBody, $signature)) {
                return JsonResponder::error($response, 'invalid_signature', 'Invalid or missing webhook signature.', 400);
            }

            try {
                $notification = PayNowNotification::fromRawBody($rawBody);
            } catch (RuntimeException) {
                return JsonResponder::error($response, 'invalid_payload', 'Malformed webhook payload.', 400);
            }

            try {
                // PayNow's notification carries no amount (unlike Tpay's) — see
                // PayNowNotification's docblock for why 0.0 here is correct, not
                // a placeholder: applyGatewayNotification() never reads it.
                $result = $registrations->applyGatewayNotification(
                    $notification->transactionId,
                    $notification->paid,
                    0.0
                );
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'gateway_transaction_unknown') {
                    return JsonResponder::error($response, 'gateway_transaction_unknown', 'Unknown transaction.', 404);
                }
                throw $e;
            }

            // E1.10 seam (mail — out of scope here per the ticket): a confirmation
            // email would be queued right here, gated on
            // `$result['applied'] && $notification->paid`, i.e. only the one
            // delivery of this notification that actually flipped the Zapis to
            // `confirmed` — never a retried/idempotent no-op delivery.

            // PayNow requires a 200 OK or 202 Accepted with an empty body — unlike
            // Tpay's "TRUE" plaintext contract.
            return $response->withStatus(200);
        });

        $api->get('/virtual-results', function (Request $request, Response $response) use ($events, $virtualResults, $query, $isStaffRole, $canManageEvent) {
            $auth = (array) $request->getAttribute('auth');
            $role = (string) ($auth['role'] ?? '');
            $userId = (string) ($auth['sub'] ?? '');
            $filters = $query($request);

            if ($isStaffRole($role) || $role === 'organizer') {
                $eventId = (string) ($filters['eventId'] ?? '');
                if ($eventId !== '' && !$canManageEvent($events->find($eventId), $auth)) {
                    return JsonResponder::error($response, 'forbidden', 'You cannot manage this event', 403);
                }
                if ($role === 'organizer' && $eventId === '') {
                    $managedEventIds = array_map(static fn (array $event): string => (string) $event['id'], $events->byOrganizer($userId));
                    $items = array_values(array_filter($virtualResults->list($filters), static fn (array $submission): bool => in_array((string) $submission['eventId'], $managedEventIds, true)));
                    return JsonResponder::ok($response, $items);
                }
                return JsonResponder::ok($response, $virtualResults->list($filters));
            }

            $filters['userId'] = $userId;
            return JsonResponder::ok($response, $virtualResults->list($filters));
        })->add(new AuthMiddleware($jwt));

        $api->post('/virtual-results', function (Request $request, Response $response) use ($virtualResults, $body) {
            $auth = (array) $request->getAttribute('auth');
            try {
                return JsonResponder::ok(
                    $response,
                    $virtualResults->submit($body($request), (string) ($auth['sub'] ?? '')),
                    'Virtual result submitted',
                    201
                );
            } catch (RuntimeException $e) {
                $messages = [
                    'validation_error' => 'Sprawdz poprawność danych i spróbuj ponownie.',
                    'virtual_event_required' => 'Wynik można zgłosić tylko dla wydarzenia wirtualnego.',
                    'virtual_result_window_closed' => 'Wynik można zgłosić tylko w aktywnym oknie startowym.',
                    'virtual_result_registration_required' => 'Nie znaleziono aktywnej rejestracji na ten dystans.',
                    'virtual_result_duplicate' => 'Wynik dla tego dystansu został już zgłoszony.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udało się zgłosić wyniku.', 422);
            }
        })->add(new AuthMiddleware($jwt, ['participant']));

        $api->patch('/virtual-results/{id}/review', function (Request $request, Response $response, array $args) use ($events, $virtualResults, $body, $canManageEvent) {
            $submission = $virtualResults->find((string) $args['id']);
            if (!$submission) {
                return JsonResponder::error($response, 'not_found', 'Submission not found', 404);
            }
            if (!$canManageEvent($events->find((string) $submission['eventId']), (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'You cannot manage this event', 403);
            }

            $payload = $body($request);
            $auth = (array) $request->getAttribute('auth');
            try {
                return JsonResponder::ok($response, $virtualResults->review(
                    (string) $args['id'],
                    (string) ($payload['status'] ?? ''),
                    (string) ($auth['sub'] ?? ''),
                    isset($payload['reason']) ? (string) $payload['reason'] : null
                ));
            } catch (RuntimeException $e) {
                return JsonResponder::error($response, $e->getMessage(), 'Nie udało się zweryfikować wyniku.', 422);
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->get('/results', function (Request $request, Response $response) use ($results, $query) {
            $page = $results->list($query($request));
            return JsonResponder::paginated($response, $page['data'], $page['total'], $page['page'], $page['pageSize']);
        });
        $api->get('/events/{eventId}/results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->byEvent($args['eventId'], $request->getQueryParams()['distanceId'] ?? null)));
        $api->get('/users/{userId}/results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->byUser($args['userId'])));
        $api->post('/results', function (Request $request, Response $response) use ($events, $results, $body, $canUploadResults, $logActivity) {
            $payload = $body($request);
            $event = $events->find((string) ($payload['eventId'] ?? ''));
            $auth = (array) $request->getAttribute('auth');
            if (!$canUploadResults($event, $auth)) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be uploaded only for active or past events you manage.', 403);
            }
            $created = $results->create($payload);
            $logActivity($auth, 'result_created', 'Dodano wynik', 'result', (string) ($created['id'] ?? ''), (string) ($created['userName'] ?? ''));
            return JsonResponder::ok($response, $created, 'Result created', 201);
        })
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/results/{id}', function (Request $request, Response $response, array $args) use ($events, $results, $body, $canUploadResults, $logActivity) {
            $result = $results->find((string) $args['id']);
            if (!$result) {
                return JsonResponder::error($response, 'not_found', 'Result not found', 404);
            }
            $event = $events->find((string) $result['eventId']);
            $auth = (array) $request->getAttribute('auth');
            if (!$canUploadResults($event, $auth)) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be edited only for active or past events you manage.', 403);
            }
            $updated = $results->update($args['id'], $body($request));
            $logActivity($auth, 'result_updated', 'Zaktualizowano wynik', 'result', (string) $args['id'], (string) ($updated['userName'] ?? $result['userName'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->delete('/results/{id}', function (Request $request, Response $response, array $args) use ($events, $results, $canUploadResults, $logActivity) {
            $result = $results->find((string) $args['id']);
            if (!$result) {
                return JsonResponder::error($response, 'not_found', 'Result not found', 404);
            }
            $event = $events->find((string) $result['eventId']);
            $auth = (array) $request->getAttribute('auth');
            if (!$canUploadResults($event, $auth)) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be deleted only for active or past events you manage.', 403);
            }
            $results->delete($args['id']);
            $logActivity($auth, 'result_deleted', 'Usunięto wynik', 'result', (string) $args['id'], (string) ($result['userName'] ?? ''));
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/users/{userId}/personal-records', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->personalRecords($args['userId'])));
        $api->get('/events/{eventId}/team-results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->teamResults($args['eventId'])));
        $api->get('/events/{eventId}/result-documents', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->documents($args['eventId'])));
        $api->post('/events/{eventId}/result-documents', function (Request $request, Response $response, array $args) use ($events, $results, $body, $canUploadResults) {
            $event = $events->find((string) $args['eventId']);
            if (!$canUploadResults($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be uploaded only for active or past events you manage.', 403);
            }
            return JsonResponder::ok($response, $results->createDocument($args['eventId'], $body($request)), 'Document uploaded', 201);
        })
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->post('/events/{eventId}/distances/{distanceId}/result-imports', function (Request $request, Response $response, array $args) use ($events, $resultImports, $uploads, $canUploadResults) {
            $event = $events->find((string) $args['eventId']);
            if (!$canUploadResults($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be uploaded only for active or past events you manage.', 403);
            }

            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                return JsonResponder::error($response, 'validation_error', 'Wybierz plik CSV, XLSX albo PDF.', 422);
            }

            $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $mode = (string) ($payload['mode'] ?? 'replace');
            $auth = (array) $request->getAttribute('auth');

            try {
                $stored = $uploads->storeResultImport($file);
                $batch = $resultImports->create(
                    (string) $args['eventId'],
                    (string) $args['distanceId'],
                    $mode,
                    $stored,
                    (string) ($auth['sub'] ?? '')
                );
                return JsonResponder::ok($response, $batch, 'Import przygotowany', 201);
            } catch (RuntimeException $e) {
                $messages = [
                    'file_too_large' => 'Plik jest za duzy. Maksymalny rozmiar to 5 MB.',
                    'unsupported_file_type' => 'Nieobslugiwany format pliku. Wgraj CSV, XLSX albo PDF.',
                    'empty_import_file' => 'Plik nie zawiera danych do importu.',
                    'xlsx_not_supported' => 'Serwer PHP nie obsluguje odczytu XLSX. Wgraj CSV albo wlacz rozszerzenie ZipArchive.',
                    'pdf_text_not_detected' => 'Nie udalo sie odczytac tekstu z PDF. To moze byc skan wymagajacy OCR.',
                    'pdf_table_not_detected' => 'Odczytano tekst z PDF, ale nie wykryto wierszy wynikow. Wgraj CSV/XLSX albo popraw dane recznie po konwersji.',
                    'validation_error' => 'Sprawdz poprawność danych i spróbuj ponownie.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udalo sie przygotowac importu.', 422);
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->get('/result-imports/{id}', function (Request $request, Response $response, array $args) use ($events, $resultImports, $canManageEvent) {
            $batch = $resultImports->find((string) $args['id']);
            if (!$batch) {
                return JsonResponder::error($response, 'not_found', 'Import not found', 404);
            }
            if (!$canManageEvent($events->find((string) $batch['eventId']), (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'You cannot manage this event', 403);
            }
            return JsonResponder::ok($response, $batch);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->patch('/result-imports/{id}/mapping', function (Request $request, Response $response, array $args) use ($events, $resultImports, $body, $canUploadResults) {
            $batch = $resultImports->find((string) $args['id']);
            if (!$batch) {
                return JsonResponder::error($response, 'not_found', 'Import not found', 404);
            }
            if (!$canUploadResults($events->find((string) $batch['eventId']), (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be uploaded only for active or past events you manage.', 403);
            }
            $payload = $body($request);
            try {
                return JsonResponder::ok($response, $resultImports->analyze((string) $args['id'], $payload['mapping'] ?? [], $payload['sheetName'] ?? null));
            } catch (RuntimeException $e) {
                return JsonResponder::error($response, $e->getMessage(), 'Nie udalo sie przeanalizowac importu.', 422);
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->patch('/result-import-rows/{id}', function (Request $request, Response $response, array $args) use ($events, $resultImports, $body, $canUploadResults) {
            try {
                $existingBatch = $resultImports->batchForRow((string) $args['id']);
                if (!$existingBatch) {
                    return JsonResponder::error($response, 'not_found', 'Import row not found', 404);
                }
                if (!$canUploadResults($events->find((string) $existingBatch['eventId']), (array) $request->getAttribute('auth'))) {
                    return JsonResponder::error($response, 'result_event_not_open', 'Results can be uploaded only for active or past events you manage.', 403);
                }
                $batch = $resultImports->updateRow((string) $args['id'], $body($request));
                return JsonResponder::ok($response, $batch);
            } catch (RuntimeException $e) {
                $status = $e->getMessage() === 'not_found' ? 404 : 422;
                return JsonResponder::error($response, $e->getMessage(), 'Nie udalo sie zaktualizowac wiersza importu.', $status);
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        $api->post('/result-imports/{id}/commit', function (Request $request, Response $response, array $args) use ($events, $resultImports, $canUploadResults) {
            $batch = $resultImports->find((string) $args['id']);
            if (!$batch) {
                return JsonResponder::error($response, 'not_found', 'Import not found', 404);
            }
            if (!$canUploadResults($events->find((string) $batch['eventId']), (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be uploaded only for active or past events you manage.', 403);
            }
            try {
                return JsonResponder::ok($response, $resultImports->commit((string) $args['id']), 'Wyniki zapisane');
            } catch (RuntimeException $e) {
                $messages = [
                    'result_import_unresolved_rows' => 'Rozwiaz wszystkie problematyczne wiersze przed zatwierdzeniem importu.',
                    'validation_error' => 'Nie znaleziono wydarzenia albo dystansu dla importu.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udalo sie zatwierdzic importu.', 422);
            }
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

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
        $api->post('/series', function (Request $request, Response $response) use ($series, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $created = $series->create($body($request), $auth);
            $logActivity($auth, 'series_created', 'Utworzono serię', 'series', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Series created', 201);
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->patch('/series/{id}', function (Request $request, Response $response, array $args) use ($series, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $series->find((string) $args['id']);
            $updated = $series->update($args['id'], $body($request));
            $logActivity($auth, 'series_updated', 'Zaktualizowano serię', 'series', (string) $args['id'], (string) ($updated['name'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/series/{id}/archive', function (Request $request, Response $response, array $args) use ($series, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $archived = $series->archive($args['id']);
            $logActivity($auth, 'series_archived', 'Zarchiwizowano serię', 'series', (string) $args['id'], (string) ($archived['name'] ?? ''));
            return JsonResponder::ok($response, $archived, 'Series archived');
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));

        $api->get('/shop/categories', function (Request $request, Response $response) use ($shop, $optionalAuth, $isStaffRole) {
            $filters = $request->getQueryParams();
            $includeArchived = !empty($filters['includeArchived']) && $isStaffRole((string) ($optionalAuth($request)['role'] ?? ''));
            return JsonResponder::ok($response, $shop->categories($includeArchived));
        });
        $api->post('/shop/categories', function (Request $request, Response $response) use ($shop, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $created = $shop->createCategory($body($request));
            $logActivity($auth, 'shop_category_created', 'Utworzono kategorię sklepu', 'shopCategory', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Category created', 201);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/shop/categories/{id}', function (Request $request, Response $response, array $args) use ($shop, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $updated = $shop->updateCategory($args['id'], $body($request));
            $logActivity($auth, 'shop_category_updated', 'Zaktualizowano kategorię sklepu', 'shopCategory', (string) $args['id'], (string) ($updated['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->delete('/shop/categories/{id}', function (Request $request, Response $response, array $args) use ($shop, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = null;
            foreach ($shop->categories(true) as $category) {
                if ($category['id'] === $args['id']) {
                    $existing = $category;
                    break;
                }
            }
            $shop->archiveCategory($args['id']);
            $logActivity($auth, 'shop_category_deleted', 'Usunięto kategorię sklepu', 'shopCategory', (string) $args['id'], (string) ($existing['name'] ?? ''));
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $adminRoles));

        // TODO: move these two catalogues into the database. They are the last shop data
        // still hard-coded, so a courier price or a new payment option needs a deploy, and
        // neither the seeder nor the admin can touch them. Orders already store the chosen
        // method as a JSON snapshot, so existing orders keep rendering whatever these
        // return today — the migration only has to cover the pick list, not history.
        // Needs: shop_delivery_methods + shop_payment_methods tables, a repository, seeder
        // entries, and an admin screen. Left as-is until the payment work lands.
        $api->get('/shop/delivery-methods', static fn (Request $request, Response $response) => JsonResponder::ok($response, [
            ['id' => 'courier', 'name' => 'Kurier', 'price' => 15.99, 'estimatedDays' => '1-2 dni'],
            ['id' => 'pickup', 'name' => 'Odbior osobisty', 'price' => 0, 'estimatedDays' => 'w dniu zawodow'],
        ]));
        $api->get('/shop/payment-methods', static fn (Request $request, Response $response) => JsonResponder::ok($response, [
            ['id' => 'card', 'name' => 'Karta platnicza'],
            ['id' => 'blik', 'name' => 'BLIK'],
            ['id' => 'bank', 'name' => 'Przelew bankowy'],
        ]));

        $api->get('/insurance/settings', fn (Request $request, Response $response) => JsonResponder::ok($response, $insurance->getSettings()));
        $api->patch('/insurance/settings', function (Request $request, Response $response) use ($insurance, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $updated = $insurance->updateSettings($body($request));
            $logActivity($auth, 'insurance_settings_updated', 'Zaktualizowano ustawienia NNW', 'insuranceSettings', null, null);
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));

        // Catalog of NNW variants (E0.3): admin defines variants, organizers pick which
        // are offered on their event. `/insurance/settings` above is kept as a thin
        // read/write on the first catalog row for backward compatibility.
        $api->get('/insurance/variants', fn (Request $request, Response $response) => JsonResponder::ok($response, $insurance->list()));
        $api->post('/insurance/variants', function (Request $request, Response $response) use ($insurance, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $created = $insurance->create($body($request));
            $logActivity($auth, 'insurance_variant_created', 'Utworzono wariant NNW', 'insuranceVariant', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/insurance/variants/{id}', function (Request $request, Response $response, array $args) use ($insurance, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $insurance->find((string) $args['id']);
            $updated = $insurance->update($args['id'], $body($request));
            $logActivity($auth, 'insurance_variant_updated', 'Zaktualizowano wariant NNW', 'insuranceVariant', (string) $args['id'], (string) ($updated['name'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->delete('/insurance/variants/{id}', function (Request $request, Response $response, array $args) use ($insurance, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $insurance->find((string) $args['id']);
            $insurance->delete($args['id']);
            $logActivity($auth, 'insurance_variant_deleted', 'Usunięto wariant NNW', 'insuranceVariant', (string) $args['id'], (string) ($existing['name'] ?? ''));
            return JsonResponder::ok($response, ['deleted' => true]);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));

        $api->get('/shop/orders', fn (Request $request, Response $response) => JsonResponder::ok($response, $orders->list($query($request))))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->get('/shop/orders/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $orders->find($args['id'])))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/shop/orders', fn (Request $request, Response $response) => JsonResponder::ok($response, $orders->create($body($request)), 'Order created', 201))
            ->add(new AuthMiddleware($jwt, []));
        $api->patch('/shop/orders/{id}/status', function (Request $request, Response $response, array $args) use ($orders, $body, $logActivity) {
            $data = $body($request);
            $status = (string) ($data['status'] ?? '');
            $updated = $orders->updateStatus($args['id'], $status, $data['trackingNumber'] ?? null);
            $logActivity((array) $request->getAttribute('auth'), 'order_status_changed', 'Zmieniono status zamówienia na ' . $status, 'order', (string) $args['id'], (string) ($updated['id'] ?? $args['id']));
            return JsonResponder::ok($response, $updated);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->patch('/shop/orders/{id}', function (Request $request, Response $response, array $args) use ($orders, $body, $logActivity) {
            $updated = $orders->update($args['id'], $body($request));
            $logActivity((array) $request->getAttribute('auth'), 'order_updated', 'Zaktualizowano zamówienie', 'order', (string) $args['id'], (string) ($updated['id'] ?? $args['id']));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));

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
        $api->post('/shop/products', function (Request $request, Response $response) use ($shop, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $created = $shop->createProduct($body($request));
            $logActivity($auth, 'shop_product_created', 'Utworzono produkt', 'shopProduct', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Product created', 201);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/shop/products/{id}', function (Request $request, Response $response, array $args) use ($shop, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $shop->findProduct((string) $args['id']);
            $updated = $shop->updateProduct($args['id'], $body($request));
            $logActivity($auth, 'shop_product_updated', 'Zaktualizowano produkt', 'shopProduct', (string) $args['id'], (string) ($updated['name'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->delete('/shop/products/{id}', function (Request $request, Response $response, array $args) use ($shop, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $shop->findProduct((string) $args['id']);
            $shop->archiveProduct($args['id']);
            $logActivity($auth, 'shop_product_deleted', 'Usunięto produkt', 'shopProduct', (string) $args['id'], (string) ($existing['name'] ?? ''));
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
        $api->patch('/challenges/{id}', function (Request $request, Response $response, array $args) use ($challenges, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $challenges->find((string) $args['id']);
            $updated = $challenges->update($args['id'], $body($request));
            $logActivity($auth, 'challenge_updated', 'Zaktualizowano wyzwanie', 'challenge', (string) $args['id'], (string) ($updated['name'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/challenges/{id}/archive', function (Request $request, Response $response, array $args) use ($challenges, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $archived = $challenges->archive($args['id']);
            $logActivity($auth, 'challenge_archived', 'Zarchiwizowano wyzwanie', 'challenge', (string) $args['id'], (string) ($archived['name'] ?? ''));
            return JsonResponder::ok($response, $archived, 'Challenge archived');
        })
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
        $api->patch('/challenge-entries/{id}/review', function (Request $request, Response $response, array $args) use ($challenges, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $payload = $body($request);
            $status = (string) ($payload['status'] ?? 'pending');
            $reviewed = $challenges->reviewEntry($args['id'], $status, $payload['reason'] ?? null, (string) ($auth['sub'] ?? ''));
            $logActivity($auth, 'challenge_entry_reviewed', 'Zweryfikowano zgłoszenie wyzwania: ' . $status, 'challengeEntry', (string) $args['id'], (string) ($reviewed['userId'] ?? ''));
            return JsonResponder::ok($response, $reviewed);
        })->add(new AuthMiddleware($jwt, $staffRoles));

        $api->get('/info-pages', fn (Request $request, Response $response) => JsonResponder::ok($response, $pages->list()));
        $api->get('/info-pages/slug/{slug}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $pages->findBySlug($args['slug'])));
        $api->post('/info-pages', function (Request $request, Response $response) use ($pages, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $created = $pages->create($body($request));
            $logActivity($auth, 'info_page_created', 'Utworzono stronę informacyjną', 'infoPage', (string) ($created['id'] ?? ''), (string) ($created['title'] ?? $created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Page created', 201);
        })
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->patch('/info-pages/{id}', function (Request $request, Response $response, array $args) use ($pages, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $pages->find((string) $args['id']);
            $updated = $pages->update($args['id'], $body($request));
            $logActivity($auth, 'info_page_updated', 'Zaktualizowano stronę informacyjną', 'infoPage', (string) $args['id'], (string) ($updated['title'] ?? $updated['name'] ?? $existing['title'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->delete('/info-pages/{id}', function (Request $request, Response $response, array $args) use ($pages, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $pages->find((string) $args['id']);
            $pages->delete($args['id']);
            $logActivity($auth, 'info_page_deleted', 'Usunięto stronę informacyjną', 'infoPage', (string) $args['id'], (string) ($existing['title'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, ['admin']));
        $api->post('/info-pages/reorder', function (Request $request, Response $response) use ($pages, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $pages->reorder($body($request)['ids'] ?? []);
            $logActivity($auth, 'info_pages_reordered', 'Zmieniono kolejność stron informacyjnych', 'infoPage', null, null);
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, ['admin']));

        $api->get('/partners', fn (Request $request, Response $response) => JsonResponder::ok($response, $partners->list()));
        $api->get('/partners/featured', fn (Request $request, Response $response) => JsonResponder::ok($response, $partners->featured()));
        $api->get('/partners/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $partners->find((string) $args['id'])));
        $api->post('/partners', function (Request $request, Response $response) use ($partners, $body, $logActivity) {
            $payload = $body($request);
            if (trim((string) ($payload['name'] ?? '')) === '' || trim((string) ($payload['logoUrl'] ?? '')) === '') {
                return JsonResponder::error($response, 'validation_error', 'Name and logo are required', 422);
            }

            $auth = (array) $request->getAttribute('auth');
            $created = $partners->create($payload);
            $logActivity($auth, 'partner_created', 'Utworzono partnera', 'partner', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Partner created', 201);
        })->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/partners/{id}', function (Request $request, Response $response, array $args) use ($partners, $body, $logActivity) {
            $payload = $body($request);
            if (array_key_exists('name', $payload) && trim((string) $payload['name']) === '') {
                return JsonResponder::error($response, 'validation_error', 'Name is required', 422);
            }
            if (array_key_exists('logoUrl', $payload) && trim((string) $payload['logoUrl']) === '') {
                return JsonResponder::error($response, 'validation_error', 'Logo is required', 422);
            }

            $auth = (array) $request->getAttribute('auth');
            $existing = $partners->find((string) $args['id']);
            $updated = $partners->update((string) $args['id'], $payload);
            $logActivity($auth, 'partner_updated', 'Zaktualizowano partnera', 'partner', (string) $args['id'], (string) ($updated['name'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })->add(new AuthMiddleware($jwt, $adminRoles));
        $api->delete('/partners/{id}', function (Request $request, Response $response, array $args) use ($partners, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $partners->find((string) $args['id']);
            $partners->delete((string) $args['id']);
            $logActivity($auth, 'partner_deleted', 'Usunięto partnera', 'partner', (string) $args['id'], (string) ($existing['name'] ?? ''));
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $adminRoles));

        $api->get('/event-backgrounds', fn (Request $request, Response $response) => JsonResponder::ok($response, $backgrounds->list()));
        $api->get('/event-backgrounds/{id}', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $backgrounds->find($args['id'])));
        $api->get('/event-backgrounds/{id}/usages', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $backgrounds->usages($args['id'])))
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/uploads/event-backgrounds', function (Request $request, Response $response) use ($uploads) {
            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
                return JsonResponder::error($response, 'validation_error', 'Wybierz plik obrazu.', 422);
            }
            try {
                $result = $uploads->storeImage($file, 'event-backgrounds');
            } catch (RuntimeException $e) {
                $messages = [
                    'file_too_large' => 'Obraz jest za duży. Maksymalny rozmiar pliku to 5 MB.',
                    'unsupported_file_type' => 'Nieobsługiwany format obrazu. Wgraj plik JPG, PNG albo WebP.',
                    'invalid_image_dimensions' => 'Nie udało się odczytać wymiarów obrazu. Wybierz inny plik.',
                    'image_dimensions_too_large' => 'Obraz ma zbyt dużą rozdzielczość. Zmniejsz go i spróbuj ponownie.',
                    'upload_dir_unavailable' => 'Nie można teraz zapisać pliku na serwerze.',
                    'upload_failed' => 'Nie udało się wgrać pliku. Spróbuj ponownie.',
                ];
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udało się wgrać pliku. Spróbuj ponownie.', 422);
            }
            return JsonResponder::ok($response, $result, 'Tło wydarzenia zostało wgrane.', 201);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/event-backgrounds', function (Request $request, Response $response) use ($backgrounds, $body, $logActivity) {
            $payload = $body($request);
            if (trim((string) ($payload['name'] ?? '')) === '' || trim((string) ($payload['imageUrl'] ?? '')) === '') {
                return JsonResponder::error($response, 'validation_error', 'Name and imageUrl are required', 422);
            }
            $created = $backgrounds->create($payload);
            $logActivity((array) $request->getAttribute('auth'), 'event_background_created', 'Utworzono tło wydarzenia', 'eventBackground', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Background created', 201);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->patch('/event-backgrounds/{id}', function (Request $request, Response $response, array $args) use ($backgrounds, $body, $logActivity) {
            $bg = $backgrounds->find($args['id']);
            if (!$bg) {
                return JsonResponder::error($response, 'not_found', 'Background not found', 404);
            }
            $updated = $backgrounds->update($args['id'], $body($request));
            $logActivity((array) $request->getAttribute('auth'), 'event_background_updated', 'Zaktualizowano tło wydarzenia', 'eventBackground', (string) $args['id'], (string) ($updated['name'] ?? $bg['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->delete('/event-backgrounds/{id}', function (Request $request, Response $response, array $args) use ($backgrounds, $logActivity) {
            $bg = $backgrounds->find($args['id']);
            if (!$bg) {
                return JsonResponder::error($response, 'not_found', 'Background not found', 404);
            }
            $usages = $backgrounds->usages($args['id']);
            if ($usages !== []) {
                return JsonResponder::error($response, 'background_in_use', 'Background is used by one or more events', 409);
            }
            $backgrounds->delete($args['id']);
            $logActivity((array) $request->getAttribute('auth'), 'event_background_deleted', 'Usunięto tło wydarzenia', 'eventBackground', (string) $args['id'], (string) ($bg['name'] ?? ''));
            return JsonResponder::ok($response, null);
        })->add(new AuthMiddleware($jwt, $staffRoles));

        // E0.8: global list is admin/superadmin only (moderator lost blanket global read —
        // deliberate narrowing, not an oversight). Organizers get a scoped slice of the
        // same log via GET /events/{id}/activity-logs below, authorized through the
        // existing single-owner $canManageEvent check.
        $api->get('/admin/activity-logs', fn (Request $request, Response $response) => JsonResponder::ok($response, $admin->activityLogs($query($request))))
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->get('/events/{id}/activity-logs', function (Request $request, Response $response, array $args) use ($events, $admin, $canManageEvent, $query) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find($args['id']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $filters = $query($request);
            $filters['targetType'] = 'event';
            $filters['targetId'] = $args['id'];
            return JsonResponder::ok($response, $admin->activityLogs($filters));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->get('/admin/security-settings', fn (Request $request, Response $response) => JsonResponder::ok($response, $admin->securitySettings()))
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->patch('/admin/security-settings', function (Request $request, Response $response) use ($admin, $body, $logActivity) {
            $updated = $admin->updateSecuritySettings($body($request));
            $logActivity((array) $request->getAttribute('auth'), 'security_settings_updated', 'Zaktualizowano ustawienia bezpieczeństwa', 'securitySettings', null, null);
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, ['admin']));
        $api->get('/admin/online-sessions', fn (Request $request, Response $response) => JsonResponder::ok($response, $admin->onlineSessions()))
            ->add(new AuthMiddleware($jwt, $staffRoles));

        // Bilet is a view of Zapis, not a stored entity (E0.2) — derived from registrations.
        $api->get('/users/{userId}/tickets', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $registrations->ticketsByUser($args['userId'])))
            ->add(new AuthMiddleware($jwt, []));
        $api->get('/users/{userId}/certificates', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $certificates->byUser($args['userId'])))
            ->add(new AuthMiddleware($jwt, []));

        // ── Dev seed endpoint (local, and staging for signed-in staff) ─────────────
        // Everything it creates is recorded in `seed_records`, so bin/prune-demo.php
        // and a seed refresh remove it along with the file-based demo data.
        $api->post('/dev/seed-event', function (Request $request, Response $response) use ($pdo, $jwt, $users, $events, $registrations, $results, $body) {
            $env = (string) Env::get('APP_ENV', 'production');
            if (!in_array($env, ['local', 'staging'], true)) {
                return JsonResponder::error($response, 'forbidden', 'Dev endpoint is disabled in this environment', 403);
            }

            // Decode JWT manually. On local this skips the strict middleware, so an
            // expired-but-otherwise-valid token from a long dev session still works.
            $auth = [];
            $tokenValid = false;
            $header = $request->getHeaderLine('Authorization');
            if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
                try {
                    $auth = $jwt->decode($m[1]);
                    $tokenValid = true;
                } catch (\Throwable) {
                    // allow; caller identity falls back to empty, organizer flow uses seeded account
                }
            }

            // Staging is reachable from the internet, so APP_ENV is not a boundary there:
            // require a live token from someone who could create these events anyway.
            if ($env !== 'local') {
                $callerRole = (string) ($auth['role'] ?? '');
                $allowed = ['organizer', 'moderator', 'admin', 'superadmin'];
                if (!$tokenValid || !in_array($callerRole, $allowed, true)) {
                    return JsonResponder::error(
                        $response,
                        'forbidden',
                        'Dev endpoint requires a signed-in organizer or staff account',
                        403
                    );
                }
            }

            $manifestBefore = SeedManifest::snapshot($pdo);

            $payload = $body($request);
            $participantCount = max(1, min(500, (int) ($payload['participantCount'] ?? 20)));

            // JWT identity; fall back to body-supplied callerId when token is expired/absent
            $callerId = (string) ($auth['sub'] ?? $payload['callerId'] ?? '');
            $callerRole = (string) ($auth['role'] ?? $payload['callerRole'] ?? '');

            // ── 1. Resolve organizer ─────────────────────────────────────────────
            $organizer = $callerRole === 'organizer' && $callerId !== '' ? $users->find($callerId) : null;
            if ($organizer === null) {
                // Staff: create a dedicated organizer account for this seed run
                $orgEmail = 'seed.org.' . substr(bin2hex(random_bytes(4)), 0, 8) . '@devtool.pl';
                $orgNames = ['Stowarzyszenie Biegaczy Aktywnych', 'Klub Sportowy Sprint', 'RunForFun', 'Sport Events Polska', 'Active Sport Foundation'];
                $organizer = $users->create([
                    'name' => $orgNames[array_rand($orgNames)],
                    'email' => $orgEmail,
                    'password' => 'demo123',
                    'role' => 'organizer',
                ]);
            }

            // ── 2. Generate event data ───────────────────────────────────────────
            $cities = ['Warszawa', 'Kraków', 'Wrocław', 'Poznań', 'Gdańsk', 'Łódź', 'Katowice', 'Lublin', 'Rzeszów', 'Toruń'];
            $locations = ['Park Miejski', 'Bulwary Wiślane', 'Stadion Miejski', 'Błonia', 'Las Miejski', 'Centrum Sportowe'];
            $prefixes = ['Bieg', 'Maraton', 'Półmaraton', 'Grand Prix', 'Memoriał', 'Nocny Bieg', 'Cross', 'Ultramaraton'];
            $themes = ['Lasu', 'Gór', 'Miasta', 'Wiosny', 'Lata', 'Mazur', 'Tatr', 'Śląska', 'Wybrzeża', 'Podlasia'];
            $disciplines = ['running', 'cycling', 'triathlon', 'multisport'];

            $discipline = (string) ($payload['discipline'] ?? $disciplines[array_rand($disciplines)]);
            if (!in_array($discipline, $disciplines, true)) {
                $discipline = $disciplines[array_rand($disciplines)];
            }

            $city = $cities[array_rand($cities)];
            $eventName = $prefixes[array_rand($prefixes)] . ' ' . $themes[array_rand($themes)] . ' ' . date('Y');
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $eventName))) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

            // Stay within the current calendar year so the frontend year filter shows the event
            $yearStart = (int) strtotime(date('Y') . '-01-01');
            $yesterday = (int) strtotime('-1 day');
            $eventTs = random_int($yearStart, $yesterday);
            $eventDate = date('Y-m-d', $eventTs);
            $regDeadline = date('Y-m-d', $eventTs - 7 * 86400);

            $distanceOptions = [
                'running' => [['name' => '5 km', 'km' => 5], ['name' => '10 km', 'km' => 10], ['name' => 'Półmaraton', 'km' => 21.097], ['name' => 'Maraton', 'km' => 42.195]],
                'cycling' => [['name' => '25 km', 'km' => 25], ['name' => '50 km', 'km' => 50], ['name' => '100 km', 'km' => 100]],
                'triathlon' => [['name' => 'Sprint (750/20/5)', 'km' => null], ['name' => 'Olimpijski (1500/40/10)', 'km' => null]],
                'multisport' => [['name' => 'Dystans A (krótki)', 'km' => null], ['name' => 'Dystans B (długi)', 'km' => null]],
            ];
            $pool = $distanceOptions[$discipline];
            shuffle($pool);
            $distCount = min(count($pool), random_int(1, 3));
            $selectedDists = array_slice($pool, 0, $distCount);

            $distances = [];
            foreach ($selectedDists as $d) {
                $price = random_int(3, 15) * 10;
                $distId = bin2hex(random_bytes(8));
                $distances[] = [
                    'id' => $distId,
                    'name' => $d['name'],
                    'length' => $d['km'],
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'limit' => random_int(100, 500),
                    'registeredCount' => 0,
                    'price' => $price,
                ];
            }

            $isVirtual = isset($payload['isVirtual']) ? (bool) $payload['isVirtual'] : (random_int(0, 9) < 2);
            $isPrivate = false;  // seed events are always public so they're discoverable

            $eventData = [
                'name' => $eventName,
                'slug' => $slug,
                'discipline' => $discipline,
                'date' => $eventDate,
                'startDate' => $eventDate,
                'endDate' => $eventDate,
                'location' => $locations[array_rand($locations)] . ', ' . $city,
                'city' => $city,
                'description' => $eventName . ' to jedno z najpopularniejszych wydarzeń sportowych w regionie. Serdecznie zapraszamy!',
                'imageUrl' => '',
                'organizerId' => $organizer['id'],
                'organizerName' => $organizer['name'],
                'registrationDeadline' => $regDeadline,
                'distances' => $distances,
                'isVirtual' => $isVirtual,
                'isPrivate' => $isPrivate,
                'featured' => random_int(0, 4) === 0,
                'logisticsInfo' => [
                    'parking' => ['status' => 'yes', 'infoHtml' => '<p>Bezpłatny parking przy obiekcie.</p>'],
                    'raceOffice' => ['status' => 'yes', 'infoHtml' => '<p>Biuro zawodów czynne od 7:00.</p>'],
                    'medicalPoint' => ['status' => 'yes', 'infoHtml' => '<p>Punkt medyczny przy starcie.</p>'],
                    'toilets' => ['status' => 'yes', 'infoHtml' => '<p>Toalety przenośne na trasie.</p>'],
                    'timeMeasurement' => ['status' => 'yes', 'infoHtml' => '<p>Profesjonalny pomiar czasu chipem.</p>'],
                ],
                'rulesHtml' => '<h3>Regulamin</h3><p>Udział dobrowolny. Obowiązkowy numer startowy przez cały czas biegu.</p>',
                'paymentConfig' => ['type' => 'organizer_bank', 'bankAccountHolder' => $organizer['name'], 'transferTitle' => 'Wpisowe ' . $eventName],
                'paymentMethods' => [
                    ['id' => bin2hex(random_bytes(4)), 'name' => 'Przelew bankowy', 'enabled' => true],
                ],
            ];

            // Use create() directly to bypass organizer credit check
            $eventData['organizerId'] = $organizer['id'];
            $eventData['organizerName'] = !empty($organizer['companyName']) ? $organizer['companyName'] : $organizer['name'];
            $eventData['createdBy'] = $callerId;
            $event = $events->create($eventData);
            $eventId = $event['id'];

            // ── 3. Set status to past — use update() which skips transition guards ─
            $events->update($eventId, ['status' => 'past']);

            // ── 4. Generate participants & registrations & results ────────────────
            $firstNamesM = ['Andrzej', 'Bartosz', 'Dawid', 'Filip', 'Grzegorz', 'Jakub', 'Kamil', 'Łukasz', 'Marek', 'Paweł', 'Piotr', 'Rafał', 'Tomasz', 'Wojciech', 'Adam', 'Michał', 'Robert', 'Marcin', 'Artur', 'Mariusz', 'Krzysztof', 'Sebastian'];
            $firstNamesF = ['Anna', 'Barbara', 'Dorota', 'Ewa', 'Joanna', 'Katarzyna', 'Małgorzata', 'Natalia', 'Agnieszka', 'Monika', 'Marta', 'Karolina', 'Magdalena', 'Aleksandra', 'Julia', 'Patrycja', 'Renata', 'Sandra'];
            $lastNames = ['Kowalski', 'Nowak', 'Wiśniewski', 'Wójcik', 'Kowalczyk', 'Kamiński', 'Lewandowski', 'Zielińska', 'Szymański', 'Woźniak', 'Dąbrowski', 'Kozłowski', 'Jankowski', 'Mazur', 'Wojciechowski', 'Kwiatkowski', 'Krawczyk', 'Piotrowski'];
            $domains = ['gmail.com', 'wp.pl', 'onet.pl', 'o2.pl', 'interia.pl'];

            $createdParticipants = [];
            $usedEmails = [];

            for ($i = 0; $i < $participantCount; $i++) {
                $gender = random_int(0, 9) < 4 ? 'K' : 'M';
                $firstName = $gender === 'M' ? $firstNamesM[array_rand($firstNamesM)] : $firstNamesF[array_rand($firstNamesF)];
                $lastName = $lastNames[array_rand($lastNames)];
                if ($gender === 'K' && str_ends_with($lastName, 'ski')) {
                    $lastName = substr($lastName, 0, -3) . 'ska';
                }

                $baseEmail = strtolower(
                    preg_replace('/[^a-z0-9.]+/', '.', iconv('UTF-8', 'ASCII//TRANSLIT', "{$firstName}.{$lastName}"))
                );
                $email = $baseEmail . '@' . $domains[array_rand($domains)];
                $attempt = 0;
                while (in_array($email, $usedEmails, true) && $attempt < 5) {
                    $email = $baseEmail . random_int(1, 99) . '@' . $domains[array_rand($domains)];
                    $attempt++;
                }
                $usedEmails[] = $email;

                $birthYear = random_int(1965, 2005);

                // Find existing user or create new one
                $existing = $users->findByEmail($email);
                if ($existing) {
                    $createdParticipants[] = [
                        'id' => $existing['id'],
                        'name' => $existing['name'],
                        'gender' => $existing['gender'] ?? $gender,
                        'birthYear' => $existing['birthYear'] ?? $birthYear,
                        'email' => $email,
                    ];
                } else {
                    $participant = $users->create([
                        'name' => "{$firstName} {$lastName}",
                        'email' => $email,
                        'password' => 'demo123',
                        'role' => 'participant',
                        'gender' => $gender,
                        'birthYear' => $birthYear,
                        'phone' => '+48 ' . random_int(500, 799) . ' ' . random_int(100, 999) . ' ' . random_int(100, 999),
                    ]);
                    $createdParticipants[] = [
                        'id' => $participant['id'],
                        'name' => $participant['name'],
                        'gender' => $gender,
                        'birthYear' => $birthYear,
                        'email' => $email,
                    ];
                }
            }

            // Distribute participants across distances
            $distCount2 = count($distances);
            $perDist = max(1, (int) floor(count($createdParticipants) / $distCount2));
            $regCount = 0;
            $resultCount = 0;
            $currentYear = (int) date('Y');

            foreach ($distances as $distIdx => $dist) {
                $start = $distIdx * $perDist;
                $end = $distIdx === $distCount2 - 1 ? count($createdParticipants) : $start + $perDist;
                $distParticipants = array_slice($createdParticipants, $start, $end - $start);

                // Estimate times (seconds) per distance
                $pacePerKm = 320;
                if (isset($dist['length']) && $dist['length'] > 0) {
                    $basePaces = ['5 km' => 280, '10 km' => 320, 'Półmaraton' => 350, 'Maraton' => 380, '25 km' => 300, '50 km' => 330, '100 km' => 400];
                    $pacePerKm = $basePaces[$dist['name']] ?? 320;
                }
                $baseSeconds = $pacePerKm * ($dist['length'] ?? 10);

                $timings = [];
                foreach ($distParticipants as $p) {
                    $spread = (int) ($baseSeconds * 0.3);
                    $seconds = max(60, $baseSeconds + random_int(-$spread, (int) ($spread * 2)));
                    $r = random_int(0, 99);
                    $status = $r < 8 ? 'dnf' : ($r < 10 ? 'dsq' : 'finished');
                    $timings[] = ['p' => $p, 'seconds' => $seconds, 'status' => $status];
                }
                usort($timings, fn($a, $b) => $a['status'] === 'finished' && $b['status'] !== 'finished' ? -1 : ($a['status'] !== 'finished' && $b['status'] === 'finished' ? 1 : $a['seconds'] - $b['seconds']));

                $pos = 1;
                $catPositions = [];

                foreach ($timings as $bibIdx => $t) {
                    $p = $t['p'];
                    $g = $p['gender'];
                    $decade = (int) floor(($currentYear - (int) $p['birthYear']) / 10) * 10;
                    $cat = "{$g}{$decade}";
                    $catPositions[$cat] = ($catPositions[$cat] ?? 0) + 1;
                    $bibNum = str_pad((string) ($bibIdx + 1), 3, '0', STR_PAD_LEFT);
                    $isFinished = $t['status'] === 'finished';
                    $totalSec = $t['seconds'];
                    $h = (int) floor($totalSec / 3600);
                    $m = (int) floor(($totalSec % 3600) / 60);
                    $s = $totalSec % 60;
                    $timeStr = $h > 0
                        ? sprintf('%d:%02d:%02d', $h, $m, $s)
                        : sprintf('%d:%02d', $m, $s);

                    // Registration
                    $reg = $registrations->create([
                        'eventId' => $eventId,
                        'eventName' => $event['name'],
                        'userId' => $p['id'],
                        'userName' => $p['name'],
                        'userEmail' => $p['email'],
                        'distanceId' => $dist['id'],
                        'distanceName' => $dist['name'],
                        'category' => $cat,
                    ]);
                    $registrations->update($reg['id'], [
                        'status' => 'confirmed',
                        'bibNumber' => $bibNum,
                        'paymentDetails' => [
                            'startFeePaid' => true,
                            'startFeePaidAt' => $eventDate,
                            'startFeeAmount' => $dist['price'],
                            'insurancePaid' => false,
                            'merchandisePayments' => [],
                        ],
                    ]);
                    $regCount++;

                    // Result
                    $results->create([
                        'eventId' => $eventId,
                        'eventName' => $event['name'],
                        'distanceId' => $dist['id'],
                        'distanceName' => $dist['name'],
                        'userId' => $p['id'],
                        'userName' => $p['name'],
                        'bibNumber' => $bibNum,
                        'category' => $cat,
                        'time' => $isFinished ? $timeStr : strtoupper($t['status']),
                        'timeInSeconds' => $totalSec,
                        'position' => $isFinished ? $pos++ : 9999,
                        'categoryPosition' => $isFinished ? $catPositions[$cat] : 9999,
                        'status' => $t['status'],
                        'gender' => $g,
                        'birthYear' => (int) $p['birthYear'],
                    ]);
                    $resultCount++;
                }
            }

            SeedManifest::recordDiff($pdo, $manifestBefore, SeedManifest::snapshot($pdo));

            $finalEvent = $events->find($eventId);

            return JsonResponder::ok($response, [
                'event' => $finalEvent,
                'organizerEmail' => $organizer['email'] ?? null,
                'participantsCreated' => count($createdParticipants),
                'registrationsCreated' => $regCount,
                'resultsCreated' => $resultCount,
                'eventUrl' => '/events/' . ($finalEvent['slug'] ?? $slug),
            ], 'Seed event created', 201);
        });  // no middleware — APP_ENV plus, off local, a staff token is the boundary

        // --- Zawodnicy i Opieka (E0.1) ---
        // Zawodnik: profil danych startowych, byt samodzielny — może istnieć bez Konta.
        // Opieka: relacja N:M Konto <-> Zawodnik, uogólniająca dawne Authorizations (Konto -> Konto).

        // Sprawdź potencjalne duplikaty po imieniu + nazwisku + dacie urodzenia (ostrzeżenie, nie blokada).
        $api->get('/competitors/duplicates', function (Request $request, Response $response) use ($competitors, $query): Response {
            $filters = $query($request);
            $firstName = trim((string) ($filters['firstName'] ?? ''));
            $lastName = trim((string) ($filters['lastName'] ?? ''));
            if ($firstName === '' || $lastName === '') {
                return JsonResponder::ok($response, []);
            }
            $birthDate = ($filters['birthDate'] ?? '') !== '' ? (string) $filters['birthDate'] : null;
            return JsonResponder::ok($response, $competitors->findDuplicates($firstName, $lastName, $birthDate));
        })->add(new AuthMiddleware($jwt));

        // Pobierz: Zawodnicy pod moją opieką (myCompetitors) + Konta, które mają opiekę nade mną (caregivers).
        $api->get('/care', function (Request $request, Response $response) use ($competitors, $care): Response {
            $auth = (array) $request->getAttribute('auth');
            $userId = (string) ($auth['sub'] ?? '');
            $myProfile = $competitors->findByUserId($userId);

            return JsonResponder::ok($response, [
                'myCompetitors' => $care->listMyCompetitors($userId),
                'caregivers' => $myProfile !== null ? $care->listCaregivers((string) $myProfile['id']) : [],
            ]);
        })->add(new AuthMiddleware($jwt));

        // Udziel Opieki: albo nad istniejącym Kontem (po e-mailu, jak dawny "authorize"),
        // albo nad nowo utworzonym samodzielnym Zawodnikiem (np. dzieckiem, bez Konta).
        $api->post('/care', function (Request $request, Response $response) use ($competitors, $care, $users, $body): Response {
            $auth = (array) $request->getAttribute('auth');
            $kontoId = (string) ($auth['sub'] ?? '');

            if ((string) ($auth['role'] ?? '') !== 'participant') {
                return JsonResponder::error($response, 'forbidden', 'Opiekę mogą nadawać tylko uczestnicy.', 403);
            }

            $data = $body($request);
            $email = trim((string) ($data['email'] ?? ''));

            if ($email !== '') {
                // Upoważnij inne (istniejące) Konto do zapisywania mnie samego.
                $grantee = $users->findByEmail($email);
                if ($grantee === null) {
                    return JsonResponder::error($response, 'user_not_found', 'Nie znaleziono użytkownika z tym adresem e-mail.', 404);
                }
                $granteeId = (string) $grantee['id'];
                if ($granteeId === $kontoId) {
                    return JsonResponder::error($response, 'validation_error', 'Nie możesz upoważnić samego siebie.', 422);
                }

                $myProfile = $competitors->ensureForUser((array) ($users->find($kontoId) ?? ['id' => $kontoId, 'name' => '']));
                $zawodnikId = (string) $myProfile['id'];

                if ($care->exists($granteeId, $zawodnikId)) {
                    return JsonResponder::error($response, 'already_authorized', 'Ta osoba ma już nad Tobą opiekę.', 409);
                }

                $record = $care->grant($granteeId, $zawodnikId);
                return JsonResponder::ok($response, $record, 'Opieka została nadana.', 201);
            }

            // Nowy, samodzielny Zawodnik (np. dziecko) pod opieką zalogowanego Konta.
            $firstName = trim((string) ($data['firstName'] ?? ''));
            $lastName = trim((string) ($data['lastName'] ?? ''));
            if ($firstName === '' || $lastName === '') {
                return JsonResponder::error($response, 'validation_error', 'Podaj imię i nazwisko Zawodnika albo e-mail Konta do upoważnienia.', 422);
            }

            $competitor = $competitors->create([
                'firstName' => $firstName,
                'lastName' => $lastName,
                'birthDate' => $data['birthDate'] ?? null,
                'gender' => $data['gender'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'club' => $data['club'] ?? null,
            ]);
            $record = $care->grant($kontoId, (string) $competitor['id']);
            $record['competitor'] = $competitor;
            $record['duplicateWarnings'] = $competitor['duplicateWarnings'] ?? [];

            return JsonResponder::ok($response, $record, 'Zawodnik dodany, opieka nadana.', 201);
        })->add(new AuthMiddleware($jwt));

        // Cofnij Opiekę — może to zrobić opiekun albo sam Zawodnik (jeśli ma Konto).
        $api->delete('/care/{id}', function (Request $request, Response $response, array $args) use ($care): Response {
            $auth = (array) $request->getAttribute('auth');
            $userId = (string) ($auth['sub'] ?? '');
            $id = (string) ($args['id'] ?? '');

            $deleted = $care->revoke($id, $userId);
            if (!$deleted) {
                return JsonResponder::error($response, 'not_found', 'Opieka nie istnieje lub nie masz uprawnień do jej cofnięcia.', 404);
            }

            return JsonResponder::ok($response, null, 'Opieka została cofnięta.');
        })->add(new AuthMiddleware($jwt));
    });
};
