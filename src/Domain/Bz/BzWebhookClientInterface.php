<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz;

/**
 * Client-zone contract for Biuro Zawodów's critical-change webhook receiver
 * (issue 12 §1: "Platforma dodatkowo wypycha webhookiem zmiany krytyczne").
 *
 * There is currently no real BZ-side endpoint to call — confirmed by checking
 * the sibling biuro_zawodow repo before writing this: implementing that
 * receiver is explicit future BZ-side backlog (issue 12's "Lista zmian
 * wymaganych po stronie Biura Zawodów", separate repo/team, E3.7 territory).
 * This client is nonetheless a real, working HTTP client (not a stub) — built
 * against the contract issue 12 itself specifies, the same way E1.6/E1.7's
 * gateway clients were built against Tpay/PayNow's real APIs before this
 * platform had production credentials for either.
 */
interface BzWebhookClientInterface
{
    /**
     * POST to BZ's critical-change webhook. `$organizationToken` is the
     * calling Event's own `bz_organization_token` (E3.2), sent as
     * `X-BZ-Organization-Token` — the same credential BZ presents back to this
     * platform on its own pull (E3.3), reused symmetrically as this side's
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
