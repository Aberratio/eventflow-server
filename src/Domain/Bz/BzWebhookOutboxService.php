<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Bz;

/**
 * E3.4's entry point — ties BzWebhookOutboxRepository and
 * BzWebhookClientInterface together into the exact outbox pattern
 * CentralMailerOutboxService already established for E1.9: write the outbox
 * row (with its deterministic idempotency key) before the HTTP call, never
 * after, and never re-send once a key has already been recorded.
 *
 * What this class deliberately does NOT decide: whether to push at all for a
 * given Event (that is RegistrationRepository's job — it only constructs this
 * service's caller with a non-null instance, and only calls enqueue(), when
 * both BZ_WEBHOOK_BASE_URL is configured for this deployment AND the specific
 * Event has bz_integration_enabled + a bz_organization_token — mirrors
 * CriticalMailer's split between "is Central Mailer configured at all" and
 * "does this specific send have somewhere to go"). It also never decides *what*
 * the payload contains beyond passing it through untouched — the money-leakage
 * discipline (issue 12 §5, "bez kwot") is the caller's responsibility, same as
 * CentralMailerOutboxService trusts its caller for message content.
 */
final class BzWebhookOutboxService
{
    public function __construct(
        private readonly BzWebhookClientInterface $client,
        private readonly BzWebhookOutboxRepository $outbox,
    ) {
    }

    /**
     * @param array{
     *   eventId: string,
     *   registrationId: string,
     *   changeType: string,
     *   idempotencyKey: string,
     *   organizationToken: string,
     *   payload: array<string, mixed>,
     * } $message
     * @return array<string, mixed> the outbox row (existing, if this
     *         idempotency key was already recorded — BZ is never notified
     *         twice for the same transition)
     */
    public function enqueue(array $message): array
    {
        $idempotencyKey = $message['idempotencyKey'];

        // The idempotency key is the true "have we already told BZ about this
        // exact transition" check — checked here, against our own durable
        // record, before BZ's webhook is ever called. This also covers the
        // case where a previous attempt died after writing the outbox row but
        // before the HTTP response came back (see
        // BzWebhookOutboxRepository::create()'s doc comment).
        $existing = $this->outbox->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $row = $this->outbox->create([
            'eventId' => $message['eventId'],
            'registrationId' => $message['registrationId'],
            'changeType' => $message['changeType'],
            'idempotencyKey' => $idempotencyKey,
            'payload' => $message['payload'],
        ]);

        try {
            $this->client->push($message['payload'], $message['organizationToken'], $idempotencyKey);
        } catch (\Throwable $e) {
            $this->outbox->markFailed($row['id'], $e->getMessage());
            throw $e;
        }

        $this->outbox->markSent($row['id']);

        return $this->outbox->find($row['id']);
    }
}
