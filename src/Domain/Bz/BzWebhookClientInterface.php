<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz;

/**
 * Client-zone contract for Biuro Zawodów's critical-change webhook receiver
 * (the platform additionally pushes critical changes via webhook).
 *
 * The BZ-side receiver is `POST /webhooks/critical-changes` in biuro_zawodow-api.
 * This client is a real, working HTTP client (not a stub) built against the
 * documented contract, the same way the
 * payment gateway clients were built against Tpay/PayNow's real APIs before this
 * platform had production credentials for either.
 */
interface BzWebhookClientInterface
{
    /**
     * POST to BZ's critical-change webhook. `$organizationToken` is the
     * calling Event's own `bz_organization_token`, sent as
     * `X-BZ-Organization-Token` — the same credential BZ presents back to this
     * platform on its own pull, reused symmetrically as this side's
     * proof of identity. `$idempotencyKey` is sent as `Idempotency-Key`;
     * constructing a *deterministic* key is the caller's responsibility (this
     * client never invents one — same discipline as
     * CentralMailerClientInterface::enqueue()).
     *
     * @param array{
     *   eventId: string,
     *   registrationId: string,
     *   changeType: string,
     *   occurredAt: string,
     *   qrCode?: ?string,
     * } $payload
     *
     * @throws \RuntimeException on a non-2xx response or a transport failure.
     */
    public function push(array $payload, string $organizationToken, string $idempotencyKey): void;
}
