<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use ZmierzymyCzas\Database\SeedManifest;
use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Auth\ApplicationApiKeyConfig;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Domain\Bz\BzWebhookClient;
use ZmierzymyCzas\Domain\Bz\BzWebhookConfig;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxRepository;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxService;
use ZmierzymyCzas\Domain\Bz\Http\CurlHttpClient as BzCurlHttpClient;
use ZmierzymyCzas\Domain\Challenges\ChallengePointsSettingsRepository;
use ZmierzymyCzas\Domain\Challenges\ChallengePointThresholdScorer;
use ZmierzymyCzas\Domain\Challenges\ChallengeRankingCalculator;
use ZmierzymyCzas\Domain\Challenges\ChallengeRepository;
use ZmierzymyCzas\Domain\Cms\InfoPageRepository;
use ZmierzymyCzas\Domain\Cms\PartnerRepository;
use ZmierzymyCzas\Domain\Events\EventBackgroundRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
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
use ZmierzymyCzas\Domain\Registrations\StartListExportFormatter;
use ZmierzymyCzas\Domain\Results\ResultImportRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Results\VirtualResultRepository;
use ZmierzymyCzas\Domain\Series\SeriesRepository;
use ZmierzymyCzas\Domain\Teams\TeamRepository;
use ZmierzymyCzas\Domain\Certificate\CertificateRepository;
use ZmierzymyCzas\Domain\Insurance\InsuranceRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClient;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailTemplateRepository;
use ZmierzymyCzas\Domain\Mailer\Http\CurlHttpClient as MailerCurlHttpClient;
use ZmierzymyCzas\Domain\Shop\OrderRepository;
use ZmierzymyCzas\Domain\Shop\ShopRepository;
use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorMergeService;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Http\Middleware\ApiKeyAuthMiddleware;
use ZmierzymyCzas\Http\Middleware\AuthMiddleware;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Support\JsonResponder;
use ZmierzymyCzas\Support\UploadedFileStorage;

return static function (App $app, PDO $pdo, JwtService $jwt): void {
    $users = new UserRepository($pdo);
    $events = new EventRepository($pdo);
    // E3.1/E3.3: static per-application incoming API key config (Central Mailer
    // pattern) — built once here, same "isConfigured/fromEnv once at boot" stance
    // as TpayConfig/PayNowConfig/CentralMailerConfig above. E3.3's BZ pull
    // endpoint is the first route to actually wire ApiKeyAuthMiddleware in.
    $apiKeyConfig = ApplicationApiKeyConfig::fromEnv();
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
    // E1.10: Central Mailer-backed critical mail sending, built on top of E1.9's
    // outbox service. Same "isConfigured() gates whether sending is even
    // attempted" stance as Tpay/PayNow above — CriticalMailer checks
    // CentralMailerConfig::isConfigured() itself before every send, so this is
    // safe to build and hand out even when CENTRAL_MAILER_API_KEY/BASE_URL are
    // unset (local dev, CI, this repo's own test suite).
    $centralMailerConfig = CentralMailerConfig::fromEnv();
    $centralMailerClient = new CentralMailerClient(new MailerCurlHttpClient(), $centralMailerConfig);
    $centralMailerOutbox = new CentralMailerOutboxService($centralMailerClient, new CentralMailerOutboxRepository($pdo));
    $mailTemplates = new MailTemplateRepository($pdo);
    $mailer = new CriticalMailer($centralMailerConfig, $centralMailerOutbox, $mailTemplates);
    // E3.4: outbound "push zmian krytycznych" to Biuro Zawodów — same
    // "isConfigured() gates whether sending is even attempted" stance as
    // Tpay/PayNow/Central Mailer above. Unlike those, there is no separate API
    // key to read here: the outbound call authenticates with each affected
    // Event's own bz_organization_token (E3.2), resolved per-call inside
    // RegistrationRepository, not at wiring time — see BzWebhookConfig's doc
    // comment for why. Safe to build and hand out even when
    // BZ_WEBHOOK_BASE_URL is unset (local dev, CI, this repo's own test
    // suite) and even though Biuro Zawodów's own receiving endpoint does not
    // exist yet (separate repo/team backlog item) — RegistrationRepository
    // never calls it in either case.
    $bzWebhookConfig = BzWebhookConfig::fromEnv();
    $bzWebhookOutbox = $bzWebhookConfig->isConfigured()
        ? new BzWebhookOutboxService(new BzWebhookClient(new BzCurlHttpClient(), $bzWebhookConfig), new BzWebhookOutboxRepository($pdo))
        : null;
    $registrations = new RegistrationRepository($pdo, $tpayGateway, $payNowGateway, $mailer, $bzWebhookOutbox);
    $teams = new TeamRepository($pdo);
    // E1.10 mail #8 ("Zmiana/odwołanie Wydarzenia", 17 §1 row 8) — one call per
    // registered Zapis. Lives here (not inside EventRepository) for the same
    // reason bulkApproveRefundsForEvent() is orchestrated from routes.php
    // rather than from EventRepository::cancel() itself (see that method's
    // doc comment): EventRepository deliberately has no RegistrationRepository
    // dependency, so cross-repo fan-out belongs at this call-site layer.
    $notifyEventChanged = static function (array $event, string $changeSummary, string $refundStatus = '') use ($registrations, $mailer): void {
        foreach ($registrations->byEvent((string) $event['id']) as $registration) {
            if (in_array((string) ($registration['status'] ?? ''), ['cancelled', 'removed'], true)) {
                continue;
            }
            $mailer->sendEventChanged(
                (string) $event['id'],
                (string) $registration['id'],
                (string) ($registration['userEmail'] ?? ''),
                (string) ($event['name'] ?? ''),
                $changeSummary,
                $refundStatus
            );
        }
    };
    $competitors = new CompetitorRepository($pdo);
    $results = new ResultRepository($pdo);
    // E2.1: needs Registrations/Competitors to force-create a Zapis (Wariant
    // "biuro zawodow", ticket 07 pkt 13) for a participant the CSV names but
    // who has no existing Zapis - see createRegistrationForRow()'s docblock.
    $resultImports = new ResultImportRepository($pdo, $registrations, $competitors);
    $virtualResults = new VirtualResultRepository($pdo);
    $series = new SeriesRepository($pdo);
    $shop = new ShopRepository($pdo);
    $challenges = new ChallengeRepository($pdo);
    $challengePointsSettings = new ChallengePointsSettingsRepository($pdo);
    $challengePointThresholdScorer = new ChallengePointThresholdScorer($pdo, $challengePointsSettings);
    // E6.6 (issue 15 §6): shared ranking collaborator for both the Wyzwanie
    // ranking endpoint and the Series ranking endpoint below.
    $challengeRanking = new ChallengeRankingCalculator($challenges, $competitors, $challengePointThresholdScorer);
    // E1.10 mail #13 ("Ukończenie Wyzwania") — shared by every write path that
    // can cross a challenge's minCompleted threshold: manual approval
    // (reviewEntry(), below) and result-derived auto-approval (join(), which
    // runs syncResultEntries() internally). checkCompletion() itself is the
    // single idempotent gate (it marks completion_notified_at and returns
    // null on any later call for the same participation), so calling this
    // from more than one write path is safe and never double-sends.
    $notifyChallengeCompletion = static function (?array $participation) use ($challenges, $users, $mailer): void {
        if ($participation === null) {
            return;
        }
        $completion = $challenges->checkCompletion((string) $participation['challengeId'], (string) $participation['userId']);
        if ($completion === null) {
            return;
        }
        $participant = $users->find($completion['userId']);
        if ($participant === null) {
            return;
        }
        $mailer->sendChallengeCompleted(
            $completion['challengeId'] . ':' . $completion['userId'],
            (string) $participant['email'],
            $completion['challengeName'],
            rtrim((string) Env::get('APP_URL', ''), '/') . '/challenges/' . $completion['challengeId'] . '/reward'
        );
    };
    $pages = new InfoPageRepository($pdo);
    $partners = new PartnerRepository($pdo);
    $admin = new AdminRepository($pdo);
    $backgrounds = new EventBackgroundRepository($pdo);
    $insurance = new InsuranceRepository($pdo);
    $orders = new OrderRepository($pdo, $shop);
    $certificates = new CertificateRepository($pdo);
    $care = new CareRepository($pdo);
    // E8.1: scalanie profili Zawodnika — orkiestruje repozytoria powyżej,
    // sama nie zna się na autoryzacji wywołującego (patrz klasy docblock).
    $competitorMerge = new CompetitorMergeService($pdo, $competitors, $care, $registrations, $results, $challenges, $admin);
    // E1.13: "Pozycja formularza" — jeden byt dla pól systemowych i własnych.
    // ensureSystemCatalog() jest wywoływane per-request (a nie tylko w migracji)
    // żeby wydarzenia utworzone po migracji 041 (w tym w testach, które nie
    // uruchamiają migratora ponownie) też dostały swoje 13 pozycji.
    $formFields = new FormFieldRepository($pdo);
    $formFieldAnswers = new FormFieldAnswerRepository($pdo);
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
    // Shared validation for POST/PATCH /shop/products. On create ($partial =
    // false) every required field must be present and non-empty; on update
    // ($partial = true) a field is only checked when the caller actually
    // sent it, so partial PATCH payloads keep working.
    $validateShopProduct = static function (array $payload, bool $partial): ?string {
        $required = [
            'name' => 'Name',
            'slug' => 'Slug',
            'description' => 'Description',
            'category' => 'Category',
            'brand' => 'Brand',
            'imageUrl' => 'Image URL',
        ];
        foreach ($required as $field => $label) {
            if ($partial && !array_key_exists($field, $payload)) {
                continue;
            }
            if (trim((string) ($payload[$field] ?? '')) === '') {
                return $label . ' is required';
            }
        }
        if (!$partial && !array_key_exists('price', $payload)) {
            return 'Price is required';
        }
        if (array_key_exists('price', $payload)) {
            if (!is_numeric($payload['price'])) {
                return 'Price must be numeric';
            }
            if ((float) $payload['price'] < 0) {
                return 'Price must not be negative';
            }
        }
        return null;
    };
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
    // E3.2: the pasted BZ organization token must never reach a non-manager read
    // (issue 12 §3 — it is a credential-shaped identifier, unlike the rest of the
    // Event payload which this codebase already returns unfiltered to anonymous
    // readers). Applied to every read path a participant/guest can hit; manager
    // reads (organizer-owner or the same $canManageEvent-eligible staff) keep it.
    $stripBzToken = static function (array $event): array {
        $event['bzOrganizationToken'] = null;
        return $event;
    };
    $stripBzTokenFromList = static function (array $events) use ($stripBzToken): array {
        return array_map($stripBzToken, $events);
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
    // $metadata (E8.1): optional rich JSON snapshot, used today only by the
    // competitor-merge log entry (see CompetitorMergeService) — every other
    // caller keeps omitting it and behaves exactly as before.
    $logActivity = static function (array $auth, string $action, string $description, ?string $targetType = null, ?string $targetId = null, ?string $targetName = null, ?array $metadata = null) use ($admin): void {
        $admin->insertActivityLog([
            'userId' => (string) ($auth['sub'] ?? ''),
            'action' => $action,
            'description' => $description,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetName' => $targetName,
            'metadata' => $metadata,
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

    $app->group('/api/v1', function (RouteCollectorProxy $api) use ($pdo, $jwt, $competitors, $competitorMerge, $care, $users, $events, $registrations, $teams, $results, $resultImports, $virtualResults, $series, $shop, $challenges, $challengePointsSettings, $challengePointThresholdScorer, $pages, $partners, $admin, $backgrounds, $uploads, $orders, $insurance, $certificates, $body, $query, $adminRoles, $staffRoles, $eventManagerRoles, $publisherRoles, $optionalAuth, $isStaffRole, $canViewEvent, $canManageEvent, $canUploadResults, $isSuperAdmin, $touchesAdminAccount, $logActivity, $tpayNotificationVerifier, $payNowNotificationVerifier, $mailer, $mailTemplates, $centralMailerOutbox, $notifyEventChanged, $notifyChallengeCompletion, $formFields, $formFieldAnswers, $stripBzToken, $stripBzTokenFromList, $apiKeyConfig, $validateShopProduct): void {
        $api->get('/health', static fn (Request $request, Response $response) => JsonResponder::ok($response, [
            'status' => 'ok',
            'env' => (string) Env::get('APP_ENV', ''),
            'url' => (string) Env::get('APP_URL', ''),
            'version' => (string) Env::get('APP_VERSION', ''),
        ]));

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

        $api->post('/auth/register', function (Request $request, Response $response) use ($users, $body, $jwt, $mailer) {
            $payload = $body($request);
            if (empty($payload['name']) || empty($payload['email']) || empty($payload['password'])) {
                return JsonResponder::error($response, 'validation_error', 'Name, email and password are required', 422);
            }
            if ($users->findByEmail((string) $payload['email'])) {
                return JsonResponder::error($response, 'email_taken', 'Email is already registered', 409);
            }
            $created = $users->create($payload);
            // E1.10 mail #1 ("Weryfikacja adresu e-mail", 17 §1 row 1). This
            // ticket does not build a full email-verification feature (no
            // `email_verified_at` column, no consuming endpoint exists in this
            // codebase) — only the mail catalog's trigger wiring is in scope.
            // The verification token is a short-lived JWT reusing JwtService
            // (already used for login sessions) rather than a new table, purely
            // to make the link in the mail meaningful; wiring an actual
            // `/auth/verify-email` consumer is a separate, later concern.
            $verificationToken = $jwt->issue(['sub' => $created['id'], 'purpose' => 'email_verification']);
            $verificationLink = rtrim((string) Env::get('APP_URL', ''), '/') . '/verify-email?token=' . $verificationToken;
            $mailer->sendEmailVerification((string) $created['id'], (string) $created['email'], $verificationLink);
            return JsonResponder::ok($response, $created, 'User registered', 201);
        });

        $api->post('/auth/logout', static fn (Request $request, Response $response) => JsonResponder::ok($response, null));
        $api->post('/auth/password/reset', function (Request $request, Response $response) use ($users, $body, $jwt, $mailer) {
            $payload = $body($request);
            $email = trim((string) ($payload['email'] ?? ''));
            $user = $email !== '' ? $users->findByEmail($email) : null;
            // E1.10 mail #2 ("Reset hasła", 17 §1 row 2). Same scope boundary as
            // registration above: only the mail trigger is wired here, not a full
            // token-consuming "set new password" endpoint (out of scope for this
            // ticket). Never reveals whether the email exists — always 200.
            if ($user !== null) {
                $ttlMinutes = max(1, (int) ((int) Env::get('JWT_TTL_SECONDS', '86400')) / 60);
                $resetToken = $jwt->issue(['sub' => $user['id'], 'purpose' => 'password_reset']);
                $resetLink = rtrim((string) Env::get('APP_URL', ''), '/') . '/reset-password?token=' . $resetToken;
                $mailer->sendPasswordReset((string) $user['id'], (string) $user['email'], $resetLink, $ttlMinutes);
            }
            return JsonResponder::ok($response, null, 'Password reset email queued');
        });

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
        $api->patch('/users/{id}/status', function (Request $request, Response $response, array $args) use ($users, $body, $isSuperAdmin, $touchesAdminAccount, $logActivity, $mailer) {
            $existing = $users->find($args['id']);
            if ($touchesAdminAccount($existing, []) && !$isSuperAdmin($request)) {
                return JsonResponder::error($response, 'forbidden', 'Only superadmin can change admin status', 403);
            }
            $payload = $body($request);
            $status = $payload['status'] ?? null;
            $updated = $users->update($args['id'], ['status' => $status]);
            $logActivity((array) $request->getAttribute('auth'), 'user_status_changed', 'Zmieniono status użytkownika na ' . (string) $status, 'user', (string) $args['id'], (string) ($updated['name'] ?? ''));
            // E1.10 mail #3 ("Blokada konta", 09 §5.11) — fires exactly on the
            // active -> blocked edge, not on every /status write (re-blocking an
            // already-blocked account, or unblocking, sends nothing).
            if ($status === 'blocked' && (string) ($existing['status'] ?? '') !== 'blocked' && $updated !== null) {
                $mailer->sendAccountLocked(
                    (string) $updated['id'],
                    (string) $updated['email'],
                    (string) ($payload['reason'] ?? 'Naruszenie regulaminu platformy.'),
                    (string) Env::get('SUPPORT_EMAIL', 'kontakt@zmierzymyczas.pl')
                );
            }
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

        $api->get('/events', function (Request $request, Response $response) use ($events, $query, $optionalAuth, $isStaffRole, $stripBzTokenFromList) {
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
            // E3.2: staff dashboards may legitimately browse this list (includeUnpublished
            // above); everyone else hitting this generic listing endpoint is a public/guest
            // reader, so the BZ token is stripped unless the requester is staff.
            $data = $isStaffRole($role) ? $page['data'] : $stripBzTokenFromList($page['data']);
            return JsonResponder::paginated($response, $data, $page['total'], $page['page'], $page['pageSize']);
        });
        $api->get('/events/featured', fn (Request $request, Response $response) => JsonResponder::ok($response, $stripBzTokenFromList($events->featured())));
        $api->get('/events/live', fn (Request $request, Response $response) => JsonResponder::ok($response, $stripBzTokenFromList($events->live())));
        $api->get('/events/slug/{slug}', function (Request $request, Response $response, array $args) use ($events, $optionalAuth, $canViewEvent, $canManageEvent, $stripBzToken) {
            $event = $events->findBySlug($args['slug']);
            $auth = $optionalAuth($request);
            $accessCode = $request->getQueryParams()['accessCode'] ?? null;
            if (!$canViewEvent($event, $auth, $accessCode !== null ? (string) $accessCode : null)) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }
            return JsonResponder::ok($response, $canManageEvent($event, $auth) ? $event : $stripBzToken($event));
        });
        $api->get('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $optionalAuth, $canViewEvent, $canManageEvent, $stripBzToken) {
            $event = $events->find($args['id']);
            $auth = $optionalAuth($request);
            $accessCode = $request->getQueryParams()['accessCode'] ?? null;
            if (!$canViewEvent($event, $auth, $accessCode !== null ? (string) $accessCode : null)) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }
            return JsonResponder::ok($response, $canManageEvent($event, $auth) ? $event : $stripBzToken($event));
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
        // E6.4 (issue 15 §3): "Wydarzenie zewnętrzne" — a barebones Event a
        // Moderator/Admin creates as the reference point for a Zawodnik's
        // manual challenge-entry proof (see EventRepository::createExternal()'s
        // doc comment). Moderator-or-above per ticket 09's role split ("weryfikacja
        // Zgłoszeń u Moderatora") — a Moderator reviewing a proof submission that
        // has no matching Event on the platform needs to be able to create the
        // reference row itself, not escalate to Admin for it.
        $api->post('/events/external', function (Request $request, Response $response) use ($events, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            try {
                $created = $events->createExternal($body($request), (string) ($auth['sub'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            $logActivity($auth, 'event_created', 'Utworzono wydarzenie zewnętrzne', 'event', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'External event created', 201);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->patch('/events/{id}', function (Request $request, Response $response, array $args) use ($events, $users, $body, $canManageEvent, $isStaffRole, $logActivity, $notifyEventChanged) {
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
            // E3.2: enabling Biuro Zawodów requires a non-empty organization token —
            // either freshly pasted in this same request, or already stored from an
            // earlier enable (re-enabling after a disable-only PATCH must not force
            // re-pasting it, since disabling never wipes the stored token). Disabling
            // is always allowed, no token required.
            if (array_key_exists('bzIntegrationEnabled', $payload) && (bool) $payload['bzIntegrationEnabled']) {
                $bzToken = array_key_exists('bzOrganizationToken', $payload)
                    ? trim((string) $payload['bzOrganizationToken'])
                    : trim((string) ($event['bzOrganizationToken'] ?? ''));
                if ($bzToken === '') {
                    return JsonResponder::error($response, 'validation_error', 'bzOrganizationToken is required to enable Biuro Zawodów integration', 422);
                }
            }
            $updated = $events->update($args['id'], $payload);
            $logActivity($auth, 'event_updated', 'Zaktualizowano wydarzenie', 'event', (string) $args['id'], (string) ($updated['name'] ?? $event['name'] ?? ''));
            // E1.10 mail #8: only "pola krytyczne" (17 §1 row 8) — date and
            // location — and only once the event is actually live (Published/
            // Finished); a Draft/Submitted edit has no registrants to notify yet.
            if ($updated !== null && in_array((string) ($event['publicationStatus'] ?? ''), ['published', 'finished'], true)) {
                $changes = [];
                if (array_key_exists('date', $payload) && (string) $payload['date'] !== (string) ($event['date'] ?? '')) {
                    $changes[] = sprintf('data wydarzenia: %s -> %s', (string) ($event['date'] ?? ''), (string) $payload['date']);
                }
                if (array_key_exists('location', $payload) && (string) $payload['location'] !== (string) ($event['location'] ?? '')) {
                    $changes[] = sprintf('miejsce: %s -> %s', (string) ($event['location'] ?? ''), (string) $payload['location']);
                }
                if ($changes !== []) {
                    $notifyEventChanged($updated, implode('; ', $changes), '');
                }
            }
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
            try {
                $event = $events->submit($args['id']);
            } catch (RuntimeException $e) {
                // E5.6 (issue 16 §2): no "pakiet organizatora" credit available to
                // spend on this submission — a state conflict (the organizer's
                // account, not this request), same 409 shape as event_limit_exceeded
                // above. The event itself is left untouched in Draft.
                if ($e->getMessage() === 'event_submit_no_organizer_credit') {
                    return JsonResponder::error($response, 'event_submit_no_organizer_credit', 'No available organizer package credit to submit this event for moderation', 409);
                }
                throw $e;
            }
            if (!$event) {
                return JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be submitted in its current status', 409);
            }
            $logActivity($auth, 'event_submitted', 'Przesłano wydarzenie do moderacji', 'event', (string) $args['id'], (string) ($event['name'] ?? ''));
            return JsonResponder::ok($response, $event);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->post('/events/{id}/approve', function (Request $request, Response $response, array $args) use ($events, $users, $logActivity, $mailer) {
            $auth = (array) $request->getAttribute('auth');
            // Approve is a one-step Submitted -> Published (E1.1) — there is no separate
            // "approved" resting state to publish from afterwards.
            $event = $events->approve($args['id'], (string) ($auth['sub'] ?? ''));
            if (!$event) {
                return JsonResponder::error($response, 'invalid_status_transition', 'Event cannot be approved in its current status', 409);
            }
            $logActivity($auth, 'event_approved', 'Zatwierdzono i opublikowano wydarzenie', 'event', (string) $args['id'], (string) ($event['name'] ?? ''));
            // E1.10 mail #9 ("Decyzja moderacji", 17 §1 row 9) — recipient is the
            // "Event Team", which (per EventRepository's own permission-matrix
            // doc comment) has no membership model yet in this codebase, so this
            // is the event's single owning organizer account, same stand-in.
            $organizer = $users->find((string) ($event['organizerId'] ?? ''));
            if ($organizer !== null) {
                $mailer->sendModerationDecision((string) $args['id'], (string) $organizer['email'], (string) $event['name'], 'zatwierdzono i opublikowano');
            }
            return JsonResponder::ok($response, $event);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->post('/events/{id}/reject', function (Request $request, Response $response, array $args) use ($events, $users, $body, $logActivity, $mailer) {
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
            $organizer = $users->find((string) ($event['organizerId'] ?? ''));
            if ($organizer !== null) {
                $mailer->sendModerationDecision((string) $args['id'], (string) $organizer['email'], (string) $event['name'], 'odrzucono', $reason);
            }
            return JsonResponder::ok($response, $event);
        })->add(new AuthMiddleware($jwt, $staffRoles));
        // Published -> Cancelled. Manual, terminal, Owner or admin only per the matrix
        // (deliberately narrower than $eventManagerRoles — no moderator, no Event Team
        // other than the owner). Bulk refunds are ticket 13, out of scope here.
        $api->post('/events/{id}/cancel', function (Request $request, Response $response, array $args) use ($events, $registrations, $adminRoles, $logActivity, $notifyEventChanged) {
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
            // E1.10 mail #8 — cancellation branch. One mail per registrant,
            // stating both the cancellation and that a refund was just
            // initiated (bulkApproveRefundsForEvent(), just above) — no separate
            // mail #14 per registrant here, see RegistrationRepository::bulkApproveOneRefund()'s doc comment.
            $notifyEventChanged($cancelled, 'wydarzenie zostało odwołane', 'Zwrot środków za opłacony start zostanie zainicjowany automatycznie.');
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
        // E7.1 (issue 23 Answer §1): joinable Drużyny for one relay Dystans,
        // each with its derived completeness (memberCount vs
        // relay_min_team_size/relay_max_team_size) — the server-side source
        // of truth the client's "join an existing team" step reads instead
        // of its old purely client-side text-grouping heuristic
        // (getExistingTeamsForEvent()). Public/unauthenticated, same as the
        // sibling /team-results route below — no Rozliczenie/PII here.
        $api->get('/events/{eventId}/distances/{distanceId}/teams', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $teams->forDistance($args['eventId'], $args['distanceId'])));
        // E7.2 (issue 23 Answer §1, "link do zaproszenia dla pozostałych
        // miejsc"): a single Drużyna lookup by its own id — the read side of
        // the invitation link a captain shares directly with a missing
        // teammate (`/events/{eventSlug}/register?joinTeam={teamId}`). The
        // team's own server-generated UUID (TeamRepository::create()) is
        // reused as the invitation token itself rather than minting a
        // separate one: it is already unguessable, and the actual join is
        // re-validated in full by RegistrationRepository::
        // resolveTeamForRegistration() at submit time regardless of how the
        // client learned the id (this endpoint only lets the client resolve
        // eventId/distanceId/completeness up front, before the invitee even
        // opens the registration form, so a full/invalid link can show a
        // clear message instead of a blank form). Public/unauthenticated,
        // same stance as the sibling /teams listing above — no
        // Rozliczenie/PII on a `teams` row. 404 when the id doesn't exist.
        $api->get('/teams/{teamId}', function (Request $request, Response $response, array $args) use ($teams) {
            $team = $teams->find($args['teamId']);
            if ($team === null) {
                return JsonResponder::error($response, 'team_not_found', 'Team was not found.', 404);
            }
            return JsonResponder::ok($response, $team);
        });
        // E7.5 (issue 23 Answer §7, "Kategoria specjalna: deklaracja ręczna
        // kapitana"): lets the captain change a previously declared value —
        // see TeamRepository::updateSpecialCategory()'s doc comment for the
        // permission model (created_by_user_id) and the editability-window
        // judgment call (open until the Event's start date). Authenticated
        // (any logged-in user may call this; the repository itself rejects
        // anyone who isn't this exact team's captain) rather than gated by a
        // role, matching resolveTeamForRegistration()'s existing stance that
        // this is a per-resource ownership check, not a staff permission.
        $api->patch('/teams/{teamId}/special-category', function (Request $request, Response $response, array $args) use ($teams, $body) {
            $auth = (array) $request->getAttribute('auth');
            $payload = $body($request);
            $specialCategory = isset($payload['specialCategory']) ? trim((string) $payload['specialCategory']) : '';
            try {
                $updated = $teams->updateSpecialCategory(
                    $args['teamId'],
                    (string) ($auth['sub'] ?? ''),
                    $specialCategory !== '' ? $specialCategory : null
                );
                return JsonResponder::ok($response, $updated, 'Team special category updated');
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'team_not_found') {
                    return JsonResponder::error($response, 'team_not_found', 'Team was not found.', 404);
                }
                if ($e->getMessage() === 'team_not_captain') {
                    return JsonResponder::error($response, 'team_not_captain', 'Only the team captain can change this team\'s special category.', 403);
                }
                if ($e->getMessage() === 'team_special_category_locked') {
                    return JsonResponder::error($response, 'team_special_category_locked', 'This team\'s special category can no longer be changed once the event has started.', 409);
                }
                if ($e->getMessage() === 'team_special_category_invalid') {
                    return JsonResponder::error($response, 'team_special_category_invalid', 'Selected special category is not configured for this distance.', 422);
                }
                throw $e;
            }
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

        // E3.3: "Pull uczestników" — the endpoint Biuro Zawodów's own polling
        // calls (issue 12 §1/§6, "kierunek domyślny synchronizacji: BZ
        // pobiera"). Two independent auth layers, both required:
        //  1. ApiKeyAuthMiddleware (E3.1, wired here for the first time) —
        //     the calling application's own `X-API-Key`, same Central Mailer
        //     pattern this platform uses outbound. Generic 401 on failure,
        //     handled entirely inside the middleware.
        //  2. The Event's own `bz_organization_token` (E3.2), presented as an
        //     `X-BZ-Organization-Token` header — chosen over a query param so
        //     the credential never lands in access logs or browser history
        //     the way a query string can. Compared with `hash_equals()`
        //     (constant-time), mirroring ApplicationApiKeyConfig::
        //     resolveApplication()'s own pattern for secret comparison.
        // A missing event is 404 with the exact same generic message
        // canViewEvent()'s callers already use elsewhere in this file — never
        // a distinct "no BZ token" message, so a caller probing IDs learns
        // nothing about which ones exist. Token mismatch, integration
        // disabled, and a missing token are all folded into the same generic
        // 403 — none of the three is distinguishable from the response.
        $api->get('/events/{eventId}/bz/participants', function (Request $request, Response $response, array $args) use ($events, $registrations) {
            $event = $events->find((string) $args['eventId']);
            if ($event === null) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }

            $presentedToken = trim($request->getHeaderLine('X-BZ-Organization-Token'));
            $configuredToken = (string) ($event['bzOrganizationToken'] ?? '');
            $tokenMatches = $presentedToken !== '' && $configuredToken !== '' && hash_equals($configuredToken, $presentedToken);

            if (!$tokenMatches || empty($event['bzIntegrationEnabled'])) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }

            return JsonResponder::ok($response, $registrations->bzParticipantList((string) $args['eventId']));
        })->add(new ApiKeyAuthMiddleware($apiKeyConfig));

        // E3.5 (issue 12 §2): the return leg of the sync contract — BZ pushing
        // locally-edited fields back, merged via last-write-wins by
        // per-field timestamp. Same two-layer auth as the pull endpoint just
        // above (ApiKeyAuthMiddleware's X-API-Key + this Event's own
        // bz_organization_token as X-BZ-Organization-Token, hash_equals'd),
        // collapsed into the exact same generic 403/404 shape for the exact
        // same reason (a caller probing ids/tokens learns nothing).
        //
        // Body shape: {"fields": {"<fieldKey>": {"value": ..., "changedAt":
        // "<ISO-8601>"}, ...}, "reason"?: "<free text, optional>"}. fieldKey
        // is the same vocabulary bzParticipantList()'s own payload and
        // FormFieldCatalog use — first_name/last_name/contact_email/category/
        // bib_number, plus any custom field already flagged visibleToBz —
        // PLUS, since E3.6 (issue 12 §4, "BZ zwraca status odprawy przy
        // każdej synchronizacji"), checked_in_at/checked_in_by: the one pair
        // BZ never pulled from bzParticipantList() in the first place,
        // because the Odprawa fact originates at BZ, not on the platform. All
        // the actual LWW/scope logic lives in
        // RegistrationRepository::applyBzFieldSync() (see its docblock) —
        // this handler is just auth, request/response shaping, and the
        // activity-log entry.
        //
        // Actor attribution: this request carries no user JWT (it's an
        // application calling in, per E3.1), so $logActivity's own
        // "actor always comes from the verified JWT" convention doesn't apply
        // here — this deliberately calls $admin->insertActivityLog()
        // directly with a synthetic, clearly-non-user actor id instead, and
        // spells out "Biuro Zawodów" in the description text itself (issue 12
        // §2: "widać jawnie, kto zmienił co, kiedy i dlaczego"). activity_logs.user_id
        // has no foreign key to `users` (checked: migration 001's table
        // definition carries no CONSTRAINT on that column), so this sentinel
        // value is safe to store.
        $api->patch('/events/{eventId}/bz/participants/{registrationId}', function (Request $request, Response $response, array $args) use ($events, $registrations, $admin, $body) {
            $event = $events->find((string) $args['eventId']);
            if ($event === null) {
                return JsonResponder::error($response, 'not_found', 'Event not found', 404);
            }

            $presentedToken = trim($request->getHeaderLine('X-BZ-Organization-Token'));
            $configuredToken = (string) ($event['bzOrganizationToken'] ?? '');
            $tokenMatches = $presentedToken !== '' && $configuredToken !== '' && hash_equals($configuredToken, $presentedToken);

            if (!$tokenMatches || empty($event['bzIntegrationEnabled'])) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }

            $registration = $registrations->find((string) $args['registrationId']);
            if ($registration === null || (string) $registration['eventId'] !== (string) $args['eventId']) {
                return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
            }

            $payload = $body($request);
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
            if ($fields === []) {
                return JsonResponder::error($response, 'validation_error', 'Podaj przynajmniej jedno pole do synchronizacji.', 422);
            }
            $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : '';

            try {
                $result = $registrations->applyBzFieldSync((string) $args['eventId'], (string) $args['registrationId'], $fields);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'registration_not_found') {
                    return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
                }
                throw $e;
            }

            $describe = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE);
            $parts = [];
            foreach ($result['applied'] as $fieldKey => $change) {
                $parts[] = sprintf('%s: %s -> %s', $fieldKey, $describe($change['old']), $describe($change['new']));
            }
            $description = $parts === []
                ? 'Biuro Zawodów przesłało synchronizację pól Zapisu (brak zmian zastosowanych)'
                : 'Biuro Zawodów zsynchronizowało pola Zapisu: ' . implode('; ', $parts);

            $rejectedParts = [];
            foreach ($result['rejected'] as $fieldKey => $rejection) {
                $rejectedParts[] = sprintf(
                    '%s (%s): BZ=%s, platforma=%s',
                    $fieldKey,
                    $rejection['reason'],
                    $describe($rejection['incomingValue']),
                    $describe($rejection['currentValue'])
                );
            }
            if ($rejectedParts !== []) {
                $description .= '. Odrzucono jako nieaktualne/poza zakresem: ' . implode('; ', $rejectedParts);
            }
            if ($reason !== '') {
                $description .= '. Powód zgłoszony przez BZ: ' . $reason;
            }

            $admin->insertActivityLog([
                'userId' => 'bz-integration',
                'action' => 'bz_participant_field_synced',
                'description' => $description,
                'targetType' => 'registration',
                'targetId' => (string) $args['registrationId'],
                'targetName' => (string) ($registration['userName'] ?? ''),
            ]);

            return JsonResponder::ok($response, $result);
        })->add(new ApiKeyAuthMiddleware($apiKeyConfig));

        // E1.13: "Pozycja formularza" (model-formularza-zapisow.md §1-§9).
        // Public read (no auth) is the form/list renderer's source — it needs
        // the same ordered list an organizer edits, just without hidden
        // internals. Manage endpoints require $eventManagerRoles, mirroring
        // the mail-templates group above.
        $api->get('/events/{eventId}/form-fields/public', function (Request $request, Response $response, array $args) use ($formFields) {
            $fields = $formFields->ensureSystemCatalog((string) $args['eventId']);
            $visible = array_values(array_filter($fields, static fn (array $f): bool => $f['state'] !== 'hidden'));
            return JsonResponder::ok($response, $visible);
        });
        $api->get('/events/{eventId}/form-fields', function (Request $request, Response $response, array $args) use ($events, $formFields, $canManageEvent) {
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            return JsonResponder::ok($response, $formFields->ensureSystemCatalog((string) $args['eventId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->post('/events/{eventId}/form-fields', function (Request $request, Response $response, array $args) use ($events, $formFields, $canManageEvent, $body) {
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            try {
                $created = $formFields->createCustom((string) $args['eventId'], $body($request));
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            return JsonResponder::ok($response, $created, 'Form field created', 201);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->patch('/events/{eventId}/form-fields/{fieldId}', function (Request $request, Response $response, array $args) use ($events, $formFields, $canManageEvent, $body) {
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $payload = $body($request);
            try {
                $field = $formFields->find((string) $args['fieldId']);
                if ($field === null || $field['eventId'] !== $args['eventId']) {
                    return JsonResponder::error($response, 'not_found', 'Nie znaleziono pozycji formularza.', 404);
                }
                if (array_key_exists('state', $payload)) {
                    $field = $formFields->updateState((string) $args['fieldId'], (string) $payload['state']);
                }
                if (array_key_exists('label', $payload) || array_key_exists('tooltip', $payload)) {
                    $field = $formFields->updateLabelAndTooltip(
                        (string) $args['fieldId'],
                        array_key_exists('label', $payload) ? $payload['label'] : ($field['labelOverride'] ?? null),
                        array_key_exists('tooltip', $payload) ? $payload['tooltip'] : ($field['tooltipOverride'] ?? null)
                    );
                }
                if (array_key_exists('publicListVisible', $payload)) {
                    $field = $formFields->updatePublicListVisibility((string) $args['fieldId'], (bool) $payload['publicListVisible']);
                }
                if (array_key_exists('visibleToBz', $payload)) {
                    $field = $formFields->updateBzVisibility((string) $args['fieldId'], (bool) $payload['visibleToBz']);
                }
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            return JsonResponder::ok($response, $field);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->delete('/events/{eventId}/form-fields/{fieldId}', function (Request $request, Response $response, array $args) use ($events, $formFields, $canManageEvent) {
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $deleted = $formFields->deleteCustom((string) $args['fieldId']);
            if (!$deleted) {
                return JsonResponder::error($response, 'validation_error', 'Pozycje systemowe można wyłącznie ukryć, nie usunąć.', 422);
            }
            return JsonResponder::ok($response, ['deleted' => true]);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        // E2.5: automatic answer-count aggregation for choice-type custom fields
        // (single_choice/multi_choice/consent) — no "zestawialne" flag, see
        // FormFieldRepository::aggregatesForEvent(). Same organizer/staff gate as
        // the rest of this form-fields group.
        $api->get('/events/{id}/form-field-aggregates', function (Request $request, Response $response, array $args) use ($events, $formFields, $canManageEvent) {
            $event = $events->find((string) $args['id']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            return JsonResponder::ok($response, $formFields->aggregatesForEvent((string) $args['id']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        // E2.6: organizer-facing start-list export (spec 21 Answer §3) — CSV
        // (Windows-1250, "realny wymóg polskiego rynku") and XLSX, one
        // Distance at a time. Same $canManageEvent gate as the rest of this
        // group ("Eksportować może Zespół Wydarzenia i role administracyjne"),
        // and — because this hands out personal data — every successful
        // export leaves an activity-log entry ("każdy eksport zostawia wpis
        // w rejestrze aktywności"), called only after the file is actually
        // built so a rejected/failed export never gets logged as if it
        // succeeded.
        $api->get('/events/{eventId}/distances/{distanceId}/start-list-export', function (Request $request, Response $response, array $args) use ($events, $registrations, $canManageEvent, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }

            $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'csv'));
            if (!in_array($format, ['csv', 'xlsx'], true)) {
                return JsonResponder::error($response, 'validation_error', 'Nieobsługiwany format eksportu. Dozwolone: csv, xlsx.', 422);
            }

            $eventId = (string) $args['eventId'];
            $distanceId = (string) $args['distanceId'];
            $export = $registrations->startListExportRows($eventId, $distanceId);

            try {
                $fileContent = $format === 'xlsx'
                    ? StartListExportFormatter::toXlsx($export['columns'], $export['rows'])
                    : StartListExportFormatter::toCsv($export['columns'], $export['rows']);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'xlsx_not_supported') {
                    return JsonResponder::error($response, 'xlsx_not_supported', 'Serwer PHP nie obsluguje zapisu XLSX (brak rozszerzenia ZipArchive). Uzyj eksportu CSV.', 500);
                }
                throw $e;
            }

            $matchingDistance = current(array_filter(
                (array) ($event['distances'] ?? []),
                static fn (array $d): bool => (string) $d['id'] === $distanceId
            ));
            $distanceName = (string) ($matchingDistance['name'] ?? $distanceId);
            $filenameBase = 'lista-startowa-' . preg_replace('/[^a-z0-9]+/i', '-', $distanceName);

            $logActivity(
                $auth,
                'startlist_exported',
                sprintf('Eksport listy startowej (%s) dla dystansu "%s"', strtoupper($format), $distanceName),
                'event',
                $eventId,
                (string) ($event['name'] ?? '')
            );

            if ($format === 'xlsx') {
                $response->getBody()->write($fileContent);
                return $response
                    ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filenameBase . '.xlsx"');
            }

            $response->getBody()->write($fileContent);
            return $response
                ->withHeader('Content-Type', 'text/csv; charset=windows-1250')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filenameBase . '.csv"');
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->put('/events/{eventId}/form-fields/reorder', function (Request $request, Response $response, array $args) use ($events, $formFields, $canManageEvent, $body) {
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $formFields->reorder((string) $args['eventId'], (array) ($body($request)['orderedIds'] ?? []));
            return JsonResponder::ok($response, $formFields->listForEvent((string) $args['eventId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        // Odpowiedzi na pola własne — zawsze przypięte do Zapisu (model F1).
        // Osobny endpoint zamiast wpięcia w POST /registrations: ten ticket
        // celowo nie przerabia tworzenia Zapisu (poza zakresem — patrz raport
        // ticketu), to jedynie udowadnia "create, validate, persist an answer"
        // end-to-end z acceptance criteria.
        $api->put('/registrations/{registrationId}/form-field-answers/{fieldId}', function (Request $request, Response $response, array $args) use ($formFields, $formFieldAnswers, $body) {
            try {
                $saved = $formFieldAnswers->saveAnswer(
                    $formFields,
                    (string) $args['fieldId'],
                    (string) $args['registrationId'],
                    $body($request)['value'] ?? null
                );
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            return JsonResponder::ok($response, $saved);
        })->add(new AuthMiddleware($jwt));
        $api->get('/registrations/{registrationId}/form-field-answers', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $formFieldAnswers->forRegistration((string) $args['registrationId'])))
            ->add(new AuthMiddleware($jwt));

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
                // E7.1 (issue 23 Answer §1): "team full" is a distinct,
                // legitimate rejection reason from the Dystans' own capacity
                // above — a Drużyna can be full while the Dystans still has
                // free slots, and vice versa.
                if ($e->getMessage() === 'registration_team_full') {
                    return JsonResponder::error($response, 'registration_team_full', 'This team already has its maximum number of members.', 409);
                }
                if ($e->getMessage() === 'registration_team_not_found') {
                    return JsonResponder::error($response, 'registration_team_not_found', 'Selected team was not found.', 404);
                }
                $teamValidationErrors = [
                    'registration_team_distance_mismatch' => 'Selected team does not belong to this event/distance.',
                    'registration_team_requires_relay_distance' => 'Teams can only be created for a relay distance.',
                    'registration_team_name_required' => 'Team name is required to create a team.',
                    // E7.5 (issue 23 Answer §7): the captain's optional Kategoria
                    // specjalna, when given, must match one of the Dystans's
                    // configured special categories.
                    'team_special_category_invalid' => 'Selected special category is not configured for this distance.',
                ];
                if (array_key_exists($e->getMessage(), $teamValidationErrors)) {
                    return JsonResponder::error($response, $e->getMessage(), $teamValidationErrors[$e->getMessage()], 422);
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

        // E1.14 (spec 10 pkt 2, "korekta wsteczna ze śladem"): the only way to
        // change a finished Event's frozen form-field snapshot — organizer (own
        // Wydarzenie) or admin/moderator, same $canManageEvent gate as mark-paid
        // above. Body is a flat fieldKey => new value map, mirroring
        // updatePaymentDetails()'s "body is the payload" shape. Rejects (409)
        // when the Zapis's Event isn't `finished` yet — correcting the live
        // Competitor/Registration record directly is how that case is already
        // handled (RegistrationRepository::correctFormSnapshot() deliberately
        // builds no parallel path for it). $logActivity below is the actual
        // "ślad w rejestrze" the spec requires: one entry per correction call,
        // carrying every changed field's old and new value inline in the
        // description, the same "encode the diff into free text" convention
        // already used by user_role_changed/event_visibility_changed above (this
        // repo's activity_logs has no separate structured-diff column).
        $api->patch('/registrations/{id}/form-snapshot', function (Request $request, Response $response, array $args) use ($registrations, $events, $canManageEvent, $logActivity, $body) {
            $auth = (array) $request->getAttribute('auth');
            $registration = $registrations->find($args['id']);
            if (!$registration) {
                return JsonResponder::error($response, 'not_found', 'Registration not found', 404);
            }
            if (!$canManageEvent($events->find((string) $registration['eventId']), $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $updates = (array) $body($request);
            if ($updates === []) {
                return JsonResponder::error($response, 'form_snapshot_correction_empty', 'Podaj przynajmniej jedną wartość do skorygowania.', 422);
            }
            try {
                $result = $registrations->correctFormSnapshot($args['id'], $updates);
            } catch (RuntimeException $e) {
                $errors = [
                    'registration_not_found' => ['not_found', 'Registration not found', 404],
                    'registration_form_snapshot_not_finished' => ['registration_form_snapshot_not_finished', 'Korekta snapshotu formularza jest możliwa tylko dla zakończonego Wydarzenia.', 409],
                ];
                if (isset($errors[$e->getMessage()])) {
                    [$code, $message, $status] = $errors[$e->getMessage()];
                    return JsonResponder::error($response, $code, $message, $status);
                }
                throw $e;
            }

            $summary = [];
            foreach ($result['changes'] as $fieldKey => $change) {
                $summary[] = sprintf(
                    '%s: %s -> %s',
                    $fieldKey,
                    json_encode($change['old'], JSON_UNESCAPED_UNICODE),
                    json_encode($change['new'], JSON_UNESCAPED_UNICODE)
                );
            }
            $logActivity(
                $auth,
                'registration_form_snapshot_corrected',
                'Korekta wsteczna snapshotu formularza Zapisu: ' . implode('; ', $summary),
                'registration',
                (string) $args['id'],
                (string) ($registration['userName'] ?? '')
            );

            return JsonResponder::ok($response, ['formFields' => $registrations->formSnapshotFor($args['id'])]);
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

            // E1.10: mail #5 ("Potwierdzenie płatności / bilet") is sent from
            // inside RegistrationRepository::applyGatewayNotification() itself,
            // after its own commit — gated there on `$paid`, and naturally only
            // for the one delivery of this notification that actually flips the
            // Zapis to `confirmed` (a retried/idempotent no-op delivery returns
            // early, before that call). No mail wiring needed at this call site.

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

            // E1.10: mail #5 ("Potwierdzenie płatności / bilet") is sent from
            // inside RegistrationRepository::applyGatewayNotification() itself,
            // after its own commit — gated there on `$paid`, and naturally only
            // for the one delivery of this notification that actually flips the
            // Zapis to `confirmed` (a retried/idempotent no-op delivery returns
            // early, before that call). No mail wiring needed at this call site.

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

        $api->patch('/virtual-results/{id}/review', function (Request $request, Response $response, array $args) use ($events, $virtualResults, $body, $canManageEvent, $mailer) {
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
                $reviewed = $virtualResults->review(
                    (string) $args['id'],
                    (string) ($payload['status'] ?? ''),
                    (string) ($auth['sub'] ?? ''),
                    isset($payload['reason']) ? (string) $payload['reason'] : null
                );
                // E1.10 mail #11 ("Weryfikacja wyniku wirtualnego", 17 §1 row 11).
                // Recipient is "zgłaszający Zawodnik/Opiekun" — this submission's
                // own userEmail, which is whichever Konto actually submitted it
                // (the Zawodnik's own, or their Opiekun's if submitted on behalf).
                if ($reviewed !== null) {
                    $mailer->sendVirtualResultVerdict(
                        (string) $args['id'],
                        (string) $submission['eventId'],
                        (string) ($reviewed['userEmail'] ?? $submission['userEmail'] ?? ''),
                        (string) ($reviewed['eventName'] ?? $submission['eventName'] ?? ''),
                        (string) ($reviewed['status'] ?? ''),
                        (string) ($reviewed['rejectionReason'] ?? '')
                    );
                }
                return JsonResponder::ok($response, $reviewed);
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
            // Spec 14 sekcja 6: "Korekta jest możliwa zawsze ... ze śladem: kto
            // zmienił, kiedy, z jakiego na jaki czas" - ten sam "zakoduj diff w
            // opisie" wzorzec co korekta snapshotu formularza (patrz
            // /registrations/{id}/form-snapshot powyzej), bo activity_logs nie
            // ma osobnej kolumny na structured diff.
            $trackedFields = ['time' => 'czas brutto', 'timeInSeconds' => 'czas brutto (s)', 'netTime' => 'czas netto', 'netTimeInSeconds' => 'czas netto (s)', 'status' => 'status'];
            $changes = [];
            foreach ($trackedFields as $field => $label) {
                $before = $result[$field] ?? null;
                $after = $updated[$field] ?? null;
                if ($before !== $after) {
                    $changes[] = sprintf('%s: %s -> %s', $label, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE));
                }
            }
            $description = $changes === [] ? 'Zaktualizowano wynik' : 'Skorygowano wynik (' . implode(', ', $changes) . ')';
            $logActivity($auth, 'result_updated', $description, 'result', (string) $args['id'], (string) ($updated['userName'] ?? $result['userName'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $eventManagerRoles));
        // E2.3 (spec 14 sekcja 6): "Organizator publikuje wyniki wstepne od
        // razu po imporcie/recznym wpisaniu ... Po weryfikacji ...
        // organizator oznacza wyniki jako oficjalne." Granulacja per
        // event+distance (klasyfikacja) - patrz docblock
        // ResultRepository::markOfficial() dla pelnego uzasadnienia. Ten sam
        // $canUploadResults co reszta mutacji /results/* (organizator/
        // admin/moderator danego wydarzenia, tylko dla published/finished
        // eventow), nie surowe $canManageEvent.
        $api->post('/events/{eventId}/distances/{distanceId}/results/publish-official', function (Request $request, Response $response, array $args) use ($events, $results, $canUploadResults, $logActivity) {
            $event = $events->find((string) $args['eventId']);
            $auth = (array) $request->getAttribute('auth');
            if (!$canUploadResults($event, $auth)) {
                return JsonResponder::error($response, 'result_event_not_open', 'Results can be published only for active or past events you manage.', 403);
            }
            $updatedCount = $results->markOfficial((string) $args['eventId'], (string) $args['distanceId']);
            $logActivity(
                $auth,
                'results_published_official',
                sprintf('Oznaczono wyniki jako oficjalne (dystans %s, %d wynikow)', (string) $args['distanceId'], $updatedCount),
                'event_distance',
                (string) $args['distanceId'],
                (string) ($event['name'] ?? '')
            );
            return JsonResponder::ok($response, [
                'eventId' => (string) $args['eventId'],
                'distanceId' => (string) $args['distanceId'],
                'publicationStatus' => 'official',
                'updatedCount' => $updatedCount,
            ]);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

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
        // E2.4 (spec 14 sekcja 3): rekord zyciowy per etykieta/dlugosc dystansu,
        // tylko starty na zywo - patrz docblock
        // ResultRepository::lifetimeRecordsForCompetitor() dla pelnego
        // uzasadnienia zapytania. Autoryzacja mirrors
        // /competitors/{id}/rotate-identity-code powyzej: sam Zawodnik (jesli
        // ma wlasne Konto), Konto sprawujace nad nim Opieke
        // (CareRepository::canRegister()), albo personel (admin/superadmin/
        // moderator) - dokladnie ten sam krag co juz decyduje, kto smie
        // dzialac w imieniu danego Zawodnika, plus staff dla wgladu
        // organizacyjnego/moderacyjnego.
        $api->get('/competitors/{id}/records', function (Request $request, Response $response, array $args) use ($competitors, $care, $results, $isStaffRole): Response {
            $auth = (array) $request->getAttribute('auth');
            $kontoId = (string) ($auth['sub'] ?? '');
            $role = (string) ($auth['role'] ?? '');
            $id = (string) ($args['id'] ?? '');

            if ($competitors->find($id) === null) {
                return JsonResponder::error($response, 'not_found', 'Zawodnik nie istnieje.', 404);
            }

            if (!$isStaffRole($role) && !$care->canRegister($kontoId, $id)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wglądu w rekordy tego Zawodnika.', 403);
            }

            return JsonResponder::ok($response, $results->lifetimeRecordsForCompetitor($id));
        })->add(new AuthMiddleware($jwt));
        $api->get('/events/{eventId}/team-results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->teamResults($args['eventId'])));
        // E2.7: live-computed team/relay classification for one event+distance -
        // see ResultRepository::teamClassification()'s docblock for why this is
        // a separate endpoint from the legacy /team-results above rather than a
        // replacement of it. Public/unauthenticated, same as the individual
        // /events/{eventId}/results classification it sits alongside.
        $api->get('/events/{eventId}/distances/{distanceId}/team-results', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $results->teamClassification($args['eventId'], $args['distanceId'])));
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

        // E2.1 (spec 14 sekcja 1): "Uczestnik bez Zapisu w systemie ... wymusza
        // utworzenie Zapisu" - jedyna operacja z ekranu recznego dopasowania,
        // ktora nie tylko wybiera istniejacego kandydata, ale tworzy nowy Zapis
        // Wariantem biura zawodow. Osobny endpoint od PATCH .../result-import-rows/{id}
        // bo semantyka jest "utworz i przypisz", nie "zmien pole".
        $api->post('/result-import-rows/{id}/create-registration', function (Request $request, Response $response, array $args) use ($events, $resultImports, $canUploadResults, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            try {
                $existingBatch = $resultImports->batchForRow((string) $args['id']);
                if (!$existingBatch) {
                    return JsonResponder::error($response, 'not_found', 'Import row not found', 404);
                }
                if (!$canUploadResults($events->find((string) $existingBatch['eventId']), $auth)) {
                    return JsonResponder::error($response, 'result_event_not_open', 'Results can be uploaded only for active or past events you manage.', 403);
                }
                $batch = $resultImports->createRegistrationForRow((string) $args['id'], (string) ($auth['sub'] ?? ''));
                $logActivity($auth, 'result_import_registration_created', 'Utworzono Zapis dla niesparowanego wiersza importu wynikow', 'result_import_row', (string) $args['id'], '');
                return JsonResponder::ok($response, $batch, 'Zapis utworzony', 201);
            } catch (RuntimeException $e) {
                $messages = [
                    'not_found' => 'Import row not found',
                    'result_import_row_missing_name' => 'Wiersz nie zawiera imienia i nazwiska - nie mozna utworzyc Zapisu.',
                    'result_import_office_variant_missing' => 'Dystans nie ma Wariantu opłaty oznaczonego jako "dostępny wyłącznie przy zapisie w biurze zawodów". Dodaj go w ustawieniach Dystansu przed dopasowaniem.',
                    'result_import_registration_creation_unsupported' => 'Ta operacja nie jest tu dostępna.',
                    'validation_error' => 'Nie znaleziono wydarzenia albo dystansu dla importu.',
                ];
                $status = $e->getMessage() === 'not_found' ? 404 : 422;
                return JsonResponder::error($response, $e->getMessage(), $messages[$e->getMessage()] ?? 'Nie udalo sie utworzyc Zapisu.', $status);
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
        // E6.6 (issue 15 §6): "Klasyfikacja Serii jest widoczna publicznie" —
        // a Series has no participations of its own, so its ranking is the
        // union of its linked Wyzwania's participants (ChallengeRepository::
        // list(['seriesId' => ...]), the same filter the admin list screen
        // already uses), points summed across them by
        // ChallengeRankingCalculator. A Series with no linked Wyzwania simply
        // returns an empty ranking rather than a 404 — an empty Series is a
        // valid, if uninteresting, state, not an error.
        $api->get('/series/{id}/ranking', function (Request $request, Response $response, array $args) use ($series, $challenges, $challengeRanking) {
            $seriesId = (string) $args['id'];
            if ($series->find($seriesId) === null) {
                return JsonResponder::error($response, 'not_found', 'Seria nie istnieje.', 404);
            }
            $challengeIds = array_column($challenges->list(['seriesId' => $seriesId, 'includeArchived' => true]), 'id');
            return JsonResponder::ok($response, $challengeRanking->rank($challengeIds));
        });
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
            $payload = $body($request);
            if (trim((string) ($payload['name'] ?? '')) === '') {
                return JsonResponder::error($response, 'validation_error', 'Name is required', 422);
            }

            $auth = (array) $request->getAttribute('auth');
            $created = $shop->createCategory($payload);
            $logActivity($auth, 'shop_category_created', 'Utworzono kategorię sklepu', 'shopCategory', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Category created', 201);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/shop/categories/{id}', function (Request $request, Response $response, array $args) use ($shop, $body, $logActivity) {
            $payload = $body($request);
            if (array_key_exists('name', $payload) && trim((string) $payload['name']) === '') {
                return JsonResponder::error($response, 'validation_error', 'Name is required', 422);
            }

            $auth = (array) $request->getAttribute('auth');
            $updated = $shop->updateCategory($args['id'], $payload);
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
            ['id' => 'paczkomat', 'name' => 'Paczkomat', 'price' => 12.99, 'estimatedDays' => '1-2 dni'],
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
        $api->post('/shop/orders', function (Request $request, Response $response) use ($orders, $users, $body, $mailer) {
            // E5.2: userId is never taken from the request body — it is always
            // the authenticated caller (auth.sub), same as e.g. the
            // registrations route above derives its own userId this way.
            $auth = (array) $request->getAttribute('auth');
            $callerId = (string) ($auth['sub'] ?? '');
            try {
                $created = $orders->create($body($request), $callerId);
            } catch (RuntimeException $e) {
                // E5.2: mirrors the registration_capacity_exceeded handling above —
                // a nonexistent/archived product or an unrecognized delivery/payment
                // method is a problem with the request itself (422), while stock
                // running out is a state conflict with what's actually left (409).
                $validationErrors = [
                    'order_invalid_product' => 'One or more products in this order are invalid or no longer available.',
                    'order_invalid_delivery_method' => 'Selected delivery method is not recognized.',
                    'order_invalid_payment_method' => 'Selected payment method is not recognized.',
                    'order_invalid_address' => 'A complete shipping address is required for this delivery method.',
                    'order_invalid_invoice_data' => 'Company name and a complete billing address are required to request an invoice.',
                    'order_invalid_nip' => 'The provided NIP (tax ID) is not a valid Polish NIP.',
                ];
                if (isset($validationErrors[$e->getMessage()])) {
                    return JsonResponder::error($response, $e->getMessage(), $validationErrors[$e->getMessage()], 422);
                }
                $conflictErrors = [
                    'order_out_of_stock' => 'One or more products in this order are out of stock.',
                    'order_insufficient_stock' => 'Requested quantity exceeds available stock for one or more products.',
                ];
                if (isset($conflictErrors[$e->getMessage()])) {
                    return JsonResponder::error($response, $e->getMessage(), $conflictErrors[$e->getMessage()], 409);
                }
                throw $e;
            }
            // E1.10 mail #12 ("Zamówienie sklepowe: potwierdzenie i zmiana
            // statusu", 17 §1 row 12) — systemic, the shop belongs to the
            // platform, not an organizer (17 §5).
            $buyer = $users->find((string) ($created['userId'] ?? ''));
            if ($buyer !== null) {
                $mailer->sendShopOrderStatus((string) $created['id'], (string) $buyer['email'], (string) $created['orderNumber'], (string) $created['status']);
            }
            return JsonResponder::ok($response, $created, 'Order created', 201);
        })
            ->add(new AuthMiddleware($jwt, []));
        $api->patch('/shop/orders/{id}/status', function (Request $request, Response $response, array $args) use ($orders, $users, $body, $logActivity, $mailer) {
            $data = $body($request);
            $status = (string) ($data['status'] ?? '');
            $updated = $orders->updateStatus($args['id'], $status, $data['trackingNumber'] ?? null);
            $logActivity((array) $request->getAttribute('auth'), 'order_status_changed', 'Zmieniono status zamówienia na ' . $status, 'order', (string) $args['id'], (string) ($updated['id'] ?? $args['id']));
            if ($updated !== null) {
                $buyer = $users->find((string) ($updated['userId'] ?? ''));
                if ($buyer !== null) {
                    $mailer->sendShopOrderStatus((string) $updated['id'], (string) $buyer['email'], (string) $updated['orderNumber'], (string) $updated['status'], (string) ($updated['trackingNumber'] ?? ''));
                }
            }
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
        $api->get('/shop/products/{id}', function (Request $request, Response $response, array $args) use ($shop, $optionalAuth, $isStaffRole) {
            $product = $shop->findProduct((string) $args['id']);
            // Archived products are staff-only, same visibility rule as the
            // catalog listing's `includeArchived` filter above — a direct ID
            // lookup must not let the public route around it.
            if ($product !== null && ($product['status'] ?? 'active') !== 'active' && !$isStaffRole((string) ($optionalAuth($request)['role'] ?? ''))) {
                return JsonResponder::ok($response, null);
            }
            return JsonResponder::ok($response, $product);
        });
        $api->post('/shop/products', function (Request $request, Response $response) use ($shop, $body, $logActivity, $validateShopProduct) {
            $payload = $body($request);
            $error = $validateShopProduct($payload, false);
            if ($error !== null) {
                return JsonResponder::error($response, 'validation_error', $error, 422);
            }

            $auth = (array) $request->getAttribute('auth');
            $created = $shop->createProduct($payload);
            $logActivity($auth, 'shop_product_created', 'Utworzono produkt', 'shopProduct', (string) ($created['id'] ?? ''), (string) ($created['name'] ?? ''));
            return JsonResponder::ok($response, $created, 'Product created', 201);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));
        $api->patch('/shop/products/{id}', function (Request $request, Response $response, array $args) use ($shop, $body, $logActivity, $validateShopProduct) {
            $payload = $body($request);
            $error = $validateShopProduct($payload, true);
            if ($error !== null) {
                return JsonResponder::error($response, 'validation_error', $error, 422);
            }

            $auth = (array) $request->getAttribute('auth');
            $existing = $shop->findProduct((string) $args['id']);
            $updated = $shop->updateProduct($args['id'], $payload);
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
            try {
                return JsonResponder::ok($response, $challenges->create($body($request), (string) ($auth['sub'] ?? '')), 'Challenge created', 201);
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
        })->add(new AuthMiddleware($jwt, $staffRoles));
        $api->patch('/challenges/{id}', function (Request $request, Response $response, array $args) use ($challenges, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $existing = $challenges->find((string) $args['id']);
            try {
                $updated = $challenges->update($args['id'], $body($request));
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            $logActivity($auth, 'challenge_updated', 'Zaktualizowano wyzwanie', 'challenge', (string) $args['id'], (string) ($updated['name'] ?? $existing['name'] ?? ''));
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));
        // E6.1: dedicated read/write for a challenge's completion condition
        // tree (issue 15 §1). GET/PATCH /challenges/{id} already embed the
        // same tree (see ChallengeRepository::hydrate()) — these exist
        // alongside them so the admin tree-builder UI can write just the
        // tree without resending the whole flat challenge payload.
        $api->get('/challenges/{id}/condition-tree', fn (Request $request, Response $response, array $args) => JsonResponder::ok($response, $challenges->getConditionTree((string) $args['id'])));
        $api->put('/challenges/{id}/condition-tree', function (Request $request, Response $response, array $args) use ($challenges, $body) {
            try {
                $saved = $challenges->saveConditionTree((string) $args['id'], $body($request));
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            return JsonResponder::ok($response, $saved, 'Condition tree saved');
        })->add(new AuthMiddleware($jwt, $staffRoles));

        // E6.2 (issue 15 §2): global, admin-configurable points-per-place
        // table a `point_threshold` node converts an Event classification
        // place into points with. Same read-open/write-admin-only shape as
        // /insurance/settings.
        $api->get('/challenge-points-settings', fn (Request $request, Response $response) => JsonResponder::ok($response, $challengePointsSettings->getSettings()));
        $api->patch('/challenge-points-settings', function (Request $request, Response $response) use ($challengePointsSettings, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            try {
                $updated = $challengePointsSettings->updateSettings($body($request));
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            $logActivity($auth, 'challenge_points_settings_updated', 'Zaktualizowano tabele punktacji Wyzwan', 'challengePointsSettings', null, null);
            return JsonResponder::ok($response, $updated);
        })
            ->add(new AuthMiddleware($jwt, $adminRoles));

        // E6.2: scores one point_threshold node of a Wyzwanie's condition
        // tree for one competitor (issue 15 §2 building block — NOT the full
        // recursive tree evaluator, which is out of scope here and belongs to
        // E6.3 "Weryfikacja automatyczna ukonczenia z Wynikow"). The node is
        // sent in the request body (its own definition — events/pointsRequired/
        // bestResultsCount, the same shape the tree read/write endpoints use)
        // rather than looked up by id, because hydrateNode() (E6.1) doesn't
        // expose node ids in the tree it returns and adding them is outside
        // this ticket's scope.
        $api->post('/challenges/{id}/condition-tree/point-score/{userId}', function (Request $request, Response $response, array $args) use ($challengePointThresholdScorer, $body) {
            $node = $body($request);
            if (($node['type'] ?? null) !== 'point_threshold') {
                return JsonResponder::error($response, 'validation_error', 'Expected a point_threshold node', 422);
            }
            return JsonResponder::ok($response, $challengePointThresholdScorer->score($node, (string) $args['userId']));
        })
            ->add(new AuthMiddleware($jwt));

        // E6.6 (issue 15 §6, "Ranking: publiczny, z respektowaniem anonimizacji"):
        // public, unauthenticated (same convention as
        // /events/{eventId}/results and publicStartList() — no
        // AuthMiddleware) — descending points, ex-aequo ties share a rank
        // ("1,1,3"), anonymized competitors masked. See
        // ChallengeRankingCalculator's docblock for the full design
        // (point-total edge case, tie convention, masking).
        $api->get('/challenges/{id}/ranking', function (Request $request, Response $response, array $args) use ($challenges, $challengeRanking) {
            $challengeId = (string) $args['id'];
            if ($challenges->find($challengeId) === null) {
                return JsonResponder::error($response, 'not_found', 'Wyzwanie nie istnieje.', 404);
            }
            return JsonResponder::ok($response, $challengeRanking->rank([$challengeId]));
        });

        $api->post('/challenges/{id}/archive', function (Request $request, Response $response, array $args) use ($challenges, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $archived = $challenges->archive($args['id']);
            $logActivity($auth, 'challenge_archived', 'Zarchiwizowano wyzwanie', 'challenge', (string) $args['id'], (string) ($archived['name'] ?? ''));
            return JsonResponder::ok($response, $archived, 'Challenge archived');
        })
            ->add(new AuthMiddleware($jwt, $staffRoles));
        $api->get('/challenge-participations', fn (Request $request, Response $response) => JsonResponder::ok($response, $challenges->participations($request->getQueryParams()['challengeId'] ?? null, $request->getQueryParams()['userId'] ?? null)))
            ->add(new AuthMiddleware($jwt));
        $api->post('/challenges/{id}/join', function (Request $request, Response $response, array $args) use ($challenges, $users, $notifyChallengeCompletion) {
            $auth = (array) $request->getAttribute('auth');
            $user = $users->find((string) ($auth['sub'] ?? ''));
            if (!$user) {
                return JsonResponder::error($response, 'not_found', 'User not found', 404);
            }
            $joined = $challenges->join($args['id'], $user);
            // E1.10 mail #13: joining runs syncResultEntries(), which can
            // auto-approve entries from already-finished results and cross
            // the completion threshold on the spot (e.g. late sign-up after
            // the event already has a result on file) — check here too, not
            // just after manual review.
            $notifyChallengeCompletion($joined);
            return JsonResponder::ok($response, $joined, 'Challenge joined', 201);
        })->add(new AuthMiddleware($jwt));
        $api->post('/challenge-entries', fn (Request $request, Response $response) => JsonResponder::ok($response, $challenges->submitEntry($body($request), (array) $request->getAttribute('auth')), 'Challenge entry submitted', 201))
            ->add(new AuthMiddleware($jwt));
        // E6.5 (issue 15 §4-5): "Uczestnictwo odblokowuje zakup Produktu" —
        // userId is always the caller's own JWT (auth.sub), same as
        // POST /shop/orders/POST /challenges/{id}/join, never something the
        // request body could spoof. Reuses ChallengeRepository::claimReward(),
        // which itself reuses OrderRepository::create() for the actual order —
        // see its doc comment for the completion gate, double-claim lock and
        // why the order/challenge writes aren't in one shared transaction.
        $api->post('/challenges/{id}/claim-reward', function (Request $request, Response $response, array $args) use ($challenges, $body) {
            $auth = (array) $request->getAttribute('auth');
            $userId = (string) ($auth['sub'] ?? '');
            $payload = $body($request);
            $productId = (string) ($payload['productId'] ?? '');
            try {
                $participation = $challenges->claimReward((string) $args['id'], $productId, $payload, $userId);
            } catch (\RuntimeException $e) {
                $notFound = ['challenge_not_found' => 'Wyzwanie nie istnieje.'];
                if (isset($notFound[$e->getMessage()])) {
                    return JsonResponder::error($response, $e->getMessage(), $notFound[$e->getMessage()], 404);
                }
                $validationErrors = [
                    'challenge_not_joined' => 'Nie dołączono do tego Wyzwania.',
                    'challenge_invalid_reward_product' => 'Wskazany produkt nie jest nagrodą tego Wyzwania.',
                    'order_invalid_product' => 'One or more products in this order are invalid or no longer available.',
                    'order_invalid_delivery_method' => 'Selected delivery method is not recognized.',
                    'order_invalid_payment_method' => 'Selected payment method is not recognized.',
                    'order_invalid_address' => 'A complete shipping address is required for this delivery method.',
                    'order_invalid_invoice_data' => 'Company name and a complete billing address are required to request an invoice.',
                    'order_invalid_nip' => 'The provided NIP (tax ID) is not a valid Polish NIP.',
                ];
                if (isset($validationErrors[$e->getMessage()])) {
                    return JsonResponder::error($response, $e->getMessage(), $validationErrors[$e->getMessage()], 422);
                }
                $forbidden = ['challenge_not_completed' => 'Wyzwanie nie zostało jeszcze ukończone.'];
                if (isset($forbidden[$e->getMessage()])) {
                    return JsonResponder::error($response, $e->getMessage(), $forbidden[$e->getMessage()], 403);
                }
                $conflictErrors = [
                    'challenge_reward_already_claimed' => 'Nagroda dla tego Wyzwania została już odebrana.',
                    'order_out_of_stock' => 'One or more products in this order are out of stock.',
                    'order_insufficient_stock' => 'Requested quantity exceeds available stock for one or more products.',
                ];
                if (isset($conflictErrors[$e->getMessage()])) {
                    return JsonResponder::error($response, $e->getMessage(), $conflictErrors[$e->getMessage()], 409);
                }
                throw $e;
            }
            return JsonResponder::ok($response, $participation, 'Reward claimed', 201);
        })->add(new AuthMiddleware($jwt));
        $api->patch('/challenge-entries/{id}/review', function (Request $request, Response $response, array $args) use ($challenges, $body, $logActivity, $notifyChallengeCompletion) {
            $auth = (array) $request->getAttribute('auth');
            $payload = $body($request);
            $status = (string) ($payload['status'] ?? 'pending');
            $reviewed = $challenges->reviewEntry($args['id'], $status, $payload['reason'] ?? null, (string) ($auth['sub'] ?? ''));
            $logActivity($auth, 'challenge_entry_reviewed', 'Zweryfikowano zgłoszenie wyzwania: ' . $status, 'challengeEntry', (string) $args['id'], (string) ($reviewed['userId'] ?? ''));
            // E1.10 mail #13 ("Ukończenie Wyzwania") — fires exactly once, the
            // first time this Zawodnik's approved entries reach the challenge's
            // minCompleted threshold (see ChallengeRepository::checkCompletion()).
            if ($status === 'approved' && $reviewed !== null) {
                $notifyChallengeCompletion($reviewed);
            }
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
                // E1.12: własny profil Zawodnika (z identityCode) — każde Konto uczestnika
                // dostaje go automatycznie przy rejestracji (UserRepository::create() woła
                // ensureForUser()), więc to prawie zawsze nie-null; klient pokazuje tu "swój"
                // kod tożsamości obok kodów Zawodników z myCompetitors (spec 11 §5.1: "mama
                // przełącza między swoim kodem, kodem Zosi i Antka").
                'myProfile' => $myProfile,
                'myCompetitors' => $care->listMyCompetitors($userId),
                'caregivers' => $myProfile !== null ? $care->listCaregivers((string) $myProfile['id']) : [],
            ]);
        })->add(new AuthMiddleware($jwt));

        // Udziel Opieki: albo nad istniejącym Kontem (po e-mailu, jak dawny "authorize"),
        // albo nad nowo utworzonym samodzielnym Zawodnikiem (np. dzieckiem, bez Konta).
        $api->post('/care', function (Request $request, Response $response) use ($competitors, $care, $users, $body, $mailer): Response {
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

            // E1.10 mail #6 ("Kod tożsamości", 17 §1 row 6) — sent once, here, at
            // the actual moment of Zawodnik creation, to the Opiekun's (this
            // $kontoId's) own Konto e-mail — a child Zawodnik created this way
            // has no e-mail of its own. See CriticalMailer::sendIdentityCode()'s
            // doc comment for the QR seam this leaves for E1.12.
            $guardian = $users->find($kontoId);
            if ($guardian !== null) {
                $mailer->sendIdentityCode((string) $competitor['id'], (string) $guardian['email'], trim($firstName . ' ' . $lastName));
            }

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

        // E1.12 ("Kod tożsamości QR"): rotacja na żądanie — Zawodnik sam (jeśli ma
        // własne Konto, czyli competitor.user_id === wywołujący), albo Konto
        // sprawujące nad nim Opiekę, może unieważnić stary `zqr_` i dostać nowy.
        // Reużywamy `$care->canRegister()` zamiast wynajdywać nowe sprawdzenie —
        // to dokładnie ta sama relacja (Opieka LUB własny profil), która już dziś
        // decyduje, kto wolno zapisać Zawodnika na zawody; rotacja kodu to kolejna
        // operacja w imieniu Zawodnika, więc ten sam krąg uprawnionych pasuje.
        $api->post('/competitors/{id}/rotate-identity-code', function (Request $request, Response $response, array $args) use ($competitors, $care): Response {
            $auth = (array) $request->getAttribute('auth');
            $kontoId = (string) ($auth['sub'] ?? '');
            $id = (string) ($args['id'] ?? '');

            if ($competitors->find($id) === null) {
                return JsonResponder::error($response, 'not_found', 'Zawodnik nie istnieje.', 404);
            }

            if (!$care->canRegister($kontoId, $id)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz Opieki nad tym Zawodnikiem.', 403);
            }

            $updated = $competitors->regenerateIdentityCode($id);
            return JsonResponder::ok($response, $updated, 'Kod tożsamości został wygenerowany na nowo.');
        })->add(new AuthMiddleware($jwt));

        // E6.6 (issue 15 §6): self-service anonymization toggle — same
        // authorization circle as rotate-identity-code just above (own
        // profile or Opieka over this Zawodnik). Setting this drives
        // CompetitorNamePresenter's masking wherever it's wired in (today:
        // the E6.6 ranking endpoints above).
        $api->post('/competitors/{id}/anonymization', function (Request $request, Response $response, array $args) use ($competitors, $care, $body): Response {
            $auth = (array) $request->getAttribute('auth');
            $kontoId = (string) ($auth['sub'] ?? '');
            $id = (string) ($args['id'] ?? '');

            if ($competitors->find($id) === null) {
                return JsonResponder::error($response, 'not_found', 'Zawodnik nie istnieje.', 404);
            }

            if (!$care->canRegister($kontoId, $id)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz Opieki nad tym Zawodnikiem.', 403);
            }

            $isAnonymized = (bool) ($body($request)['isAnonymized'] ?? false);
            $updated = $competitors->setAnonymization($id, $isAnonymized);
            return JsonResponder::ok($response, $updated, $isAnonymized ? 'Anonimizacja włączona.' : 'Anonimizacja wyłączona.');
        })->add(new AuthMiddleware($jwt));

        // --- E8.1: Scalanie profili Zawodnika (wyłącznie Moderator/Admin) ---
        // Spec Answer §1: samoobsługa jest wykluczona nawet dla opiekuna sprawującego
        // Opiekę nad oboma profilami — stąd `AuthMiddleware($jwt, ['admin', 'moderator'])`
        // na każdym z tych trzech endpointów, nie `$care->canRegister()`, i nie
        // `$isStaffRole` (który celowo NIE jest użyty tutaj — organizer nie kwalifikuje
        // się, mimo że jest "staff" gdzie indziej w tym pliku).

        // Wyszukiwanie kandydatów do porównania — nie ma formalnego mechanizmu
        // "zgłoszenia duplikatu" (spec Answer §2 krok 1); moderator po prostu szuka.
        $api->get('/admin/competitors/search', function (Request $request, Response $response) use ($competitors, $query): Response {
            $q = (string) ($query($request)['q'] ?? '');
            return JsonResponder::ok($response, $competitors->searchForMerge($q));
        })->add(new AuthMiddleware($jwt, ['admin', 'moderator']));

        // Widok "obok siebie" (spec Answer §2 krok 2) — oba profile, wszystko co
        // niosą, plus lista konfliktów Zapisów (ten sam Dystans/Wydarzenie), jeszcze
        // bez wskazania, który profil przeżywa.
        $api->get('/admin/competitors/{idA}/merge-comparison/{idB}', function (Request $request, Response $response, array $args) use ($competitorMerge): Response {
            try {
                return JsonResponder::ok($response, $competitorMerge->comparison((string) $args['idA'], (string) $args['idB']));
            } catch (\RuntimeException $e) {
                return JsonResponder::error($response, 'not_found', 'Jeden z profili Zawodnika nie istnieje.', 404);
            }
        })->add(new AuthMiddleware($jwt, ['admin', 'moderator']));

        // Wykonuje scalenie — albo wstrzymuje je, jeśli brakuje decyzji pole-po-polu
        // (`field_decisions_required`) albo rozstrzygnięcia konfliktu Zapisów
        // (`conflict_pending`, spec Answer §2 krok 6). Permanentne po sukcesie
        // (`merged`) — brak przycisku "cofnij", patrz snapshot w logu aktywności.
        $api->post('/admin/competitors/merge', function (Request $request, Response $response) use ($competitorMerge, $body, $logActivity): Response {
            $auth = (array) $request->getAttribute('auth');
            $data = $body($request);

            $survivorId = (string) ($data['survivorId'] ?? '');
            $absorbedId = (string) ($data['absorbedId'] ?? '');
            if ($survivorId === '' || $absorbedId === '') {
                return JsonResponder::error($response, 'validation_error', 'Podaj survivorId i absorbedId.', 422);
            }

            $fieldDecisions = is_array($data['fieldDecisions'] ?? null) ? $data['fieldDecisions'] : [];
            $conflictResolutions = is_array($data['conflictResolutions'] ?? null) ? $data['conflictResolutions'] : [];

            try {
                $result = $competitorMerge->merge($auth, $survivorId, $absorbedId, $fieldDecisions, $conflictResolutions);
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', 'Nie można scalić profilu z samym sobą.', 422);
            } catch (\RuntimeException $e) {
                $code = $e->getMessage();
                $message = $code === 'competitor_already_merged'
                    ? 'Jeden z profili jest już scalony.'
                    : 'Jeden z profili Zawodnika nie istnieje.';
                return JsonResponder::error($response, $code, $message, $code === 'competitor_already_merged' ? 409 : 404);
            }

            // Deliberately 200 for every outcome here, `field_decisions_required`
            // and `conflict_pending` included — these are not error conditions,
            // they are normal, expected pauses in a multi-step admin flow (same
            // idiom this file already uses for POST /care's `duplicateWarnings`:
            // a non-terminal, informational result travels in a success payload,
            // not as an HTTP error, so the client can switch on `data.status`
            // without unpacking a thrown-error's `details`). Only genuine error
            // conditions (missing ids, unknown competitor, already merged, merge
            // with self) use JsonResponder::error() above.
            $message = match (true) {
                $result['status'] === 'field_decisions_required' => 'Rozstrzygnij ręcznie pola, w których profile się różnią.',
                $result['status'] === 'conflict_pending' => 'Wykryto konflikt Zapisów na ten sam Dystans — wskaż, który zostaje.',
                // Merge itself already committed successfully (survivor absorbed
                // absorbed's data, activity log written) — a refund/cancel
                // failure on the rejected side of a conflict is a real but
                // SEPARATE problem that needs manual follow-up, never reported
                // as if the merge had failed (see CompetitorMergeService::merge()).
                !empty($result['refundFailures']) => 'Profile zostały scalone, ale rozstrzygnięcie konfliktowego Zapisu wymaga ręcznej interwencji (patrz refundFailures).',
                default => 'Profile zostały scalone.',
            };
            return JsonResponder::ok($response, $result, $message);
        })->add(new AuthMiddleware($jwt, ['admin', 'moderator']));

        // E1.10 §5 (model szablonów) — minimal server-side CRUD, no frontend in
        // this ticket (server-only, like E1.6-E1.9 before it). Reuses the same
        // $canManageEvent/$adminRoles idioms already used everywhere else in
        // this file rather than inventing a new authorization shape.
        $api->get('/admin/mail-templates', function (Request $request, Response $response) use ($mailTemplates) {
            $out = [];
            foreach (\ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog::all() as $mailType => $definition) {
                $out[] = ['mailType' => $mailType, 'overridable' => $definition['overridable'], ...$mailTemplates->resolve($mailType, null)];
            }
            return JsonResponder::ok($response, $out);
        })->add(new AuthMiddleware($jwt, $adminRoles));
        $api->put('/admin/mail-templates/{mailType}', function (Request $request, Response $response, array $args) use ($mailTemplates, $body, $logActivity) {
            $auth = (array) $request->getAttribute('auth');
            $payload = $body($request);
            try {
                $saved = $mailTemplates->saveGlobal(
                    (string) $args['mailType'],
                    (string) ($payload['subject'] ?? ''),
                    (string) ($payload['bodyHtml'] ?? ''),
                    (string) ($auth['sub'] ?? '')
                );
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            $logActivity($auth, 'mail_template_updated', 'Zaktualizowano globalny szablon maila: ' . $args['mailType'], 'mailTemplate', (string) $args['mailType'], (string) $args['mailType']);
            return JsonResponder::ok($response, $saved);
        })->add(new AuthMiddleware($jwt, $adminRoles));

        $api->get('/events/{eventId}/mail-templates', function (Request $request, Response $response, array $args) use ($events, $mailTemplates, $canManageEvent) {
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            return JsonResponder::ok($response, $mailTemplates->listForEvent((string) $args['eventId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->put('/events/{eventId}/mail-templates/{mailType}', function (Request $request, Response $response, array $args) use ($events, $mailTemplates, $canManageEvent, $body, $logActivity) {
            $event = $events->find((string) $args['eventId']);
            $auth = (array) $request->getAttribute('auth');
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $payload = $body($request);
            try {
                $saved = $mailTemplates->saveEventOverride(
                    (string) $args['mailType'],
                    (string) $args['eventId'],
                    (string) ($payload['subject'] ?? ''),
                    (string) ($payload['bodyHtml'] ?? ''),
                    (string) ($auth['sub'] ?? '')
                );
            } catch (\InvalidArgumentException $e) {
                return JsonResponder::error($response, 'validation_error', $e->getMessage(), 422);
            }
            $logActivity($auth, 'mail_template_overridden', 'Nadpisano szablon maila dla wydarzenia: ' . $args['mailType'], 'mailTemplate', (string) $args['mailType'], (string) ($event['name'] ?? ''));
            return JsonResponder::ok($response, $saved);
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
        $api->delete('/events/{eventId}/mail-templates/{mailType}', function (Request $request, Response $response, array $args) use ($events, $mailTemplates, $canManageEvent, $logActivity) {
            $event = $events->find((string) $args['eventId']);
            $auth = (array) $request->getAttribute('auth');
            if (!$canManageEvent($event, $auth)) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $mailTemplates->deleteEventOverride((string) $args['mailType'], (string) $args['eventId']);
            $logActivity($auth, 'mail_template_reverted', 'Przywrócono domyślny szablon maila dla wydarzenia: ' . $args['mailType'], 'mailTemplate', (string) $args['mailType'], (string) ($event['name'] ?? ''));
            return JsonResponder::ok($response, $mailTemplates->resolve((string) $args['mailType'], (string) $args['eventId']));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));

        // E1.10 §8 ("Przepustowość: komunikat, nie cisza") — mass-send progress
        // for mail #5, the one genuinely bulk mail in this catalog (tickets to a
        // full paid start list). Defaults to payment_confirmed but accepts any
        // mail type via ?mailType=, since the same readout works for any
        // event-scoped mail sent in bulk.
        $api->get('/events/{eventId}/mail-send-progress', function (Request $request, Response $response, array $args) use ($events, $canManageEvent, $centralMailerOutbox, $query) {
            $event = $events->find((string) $args['eventId']);
            if (!$canManageEvent($event, (array) $request->getAttribute('auth'))) {
                return JsonResponder::error($response, 'forbidden', 'Nie masz uprawnień do wykonania tej operacji.', 403);
            }
            $mailType = (string) ($query($request)['mailType'] ?? \ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog::PAYMENT_CONFIRMED);
            return JsonResponder::ok($response, $centralMailerOutbox->progressForContext((string) $args['eventId'], $mailType));
        })->add(new AuthMiddleware($jwt, $eventManagerRoles));
    });
};
