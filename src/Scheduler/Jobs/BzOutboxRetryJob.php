<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Scheduler\Jobs;

use PDO;
use ZmierzymyCzas\Domain\Bz\BzWebhookConfig;
use ZmierzymyCzas\Domain\Bz\BzWebhookOutboxService;
use ZmierzymyCzas\Scheduler\ScheduledJob;

/**
 * Retries failed / stale-queued pushes to Biuro Zawodow. Skipped while BZ is not configured
 * for this deployment (BZ_WEBHOOK_BASE_URL unset).
 */
final class BzOutboxRetryJob implements ScheduledJob
{
    public const BATCH_LIMIT = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?BzWebhookOutboxService $outbox,
        private readonly BzWebhookConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'bz.outbox_retry';
    }

    public function run(\DateTimeImmutable $now): array
    {
        if ($this->outbox === null || !$this->config->isConfigured()) {
            return ['skipped' => 1];
        }

        return $this->outbox->retryDue($now, self::BATCH_LIMIT, fn (string $eventId): ?string => $this->tokenFor($eventId));
    }

    /** Same rules as RegistrationRepository::pushBzCriticalChange(): integration on and a non-empty token. */
    private function tokenFor(string $eventId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT bz_integration_enabled AS enabled, bz_organization_token AS token FROM events WHERE id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $event = $stmt->fetch();
        if ($event === false || empty($event['enabled'])) {
            return null;
        }
        $token = trim((string) ($event['token'] ?? ''));

        return $token === '' ? null : $token;
    }
}
