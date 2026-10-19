<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz;

use ZmierzymyCzas\Support\Env;

/**
 * E3.4: "Push zmian krytycznych (rezygnacja, zwrot) webhookiem" (issue 12 §1) —
 * the deployment-wide destination this platform pushes critical-change webhooks
 * to. Unlike Central Mailer/Tpay/PayNow, there is no separate platform-wide API
 * key here: issue 12 §6 says this whole integration authenticates per
 * application with a Central-Mailer-style key, but the *direction* of that key
 * is inbound (BZ -> platform, E3.1's ApplicationApiKeyConfig — already wired for
 * E3.3's pull). For the outbound direction (platform -> BZ) this ticket instead
 * reuses each Event's own `bz_organization_token` (E3.2) symmetrically — the
 * same credential BZ already presents back to this platform when it pulls
 * (routes.php's `X-BZ-Organization-Token` check) doubles as this platform's
 * proof of identity to BZ. So the only thing that needs to live in `.env` here
 * is *where* to send the request.
 *
 * `baseUrl` is a placeholder (empty string) in dev/test — see .env.example.
 * Callers must treat "not configured" as "do not attempt to send" rather than
 * making a request that could only ever fail, exactly like
 * TpayConfig/PayNowConfig/CentralMailerConfig::isConfigured(). Biuro Zawodów's receiver
 * exists (`POST /webhooks/critical-changes` in biuro_zawodow-api); leave
 * BZ_WEBHOOK_BASE_URL empty on deployments that must not push to BZ.
 */
final class BzWebhookConfig
{
    public function __construct(
        public readonly string $baseUrl,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            baseUrl: rtrim((string) Env::get('BZ_WEBHOOK_BASE_URL', ''), '/'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }
}
