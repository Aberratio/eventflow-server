<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

/**
 * The single entry point E1.10 (and anything else that needs to send mail
 * through Central Mailer) should call — ties CentralMailerOutboxRepository and
 * CentralMailerClientInterface together into the outbox pattern recommended by
 * kontrakt-central-mailer.md §6.4: write the outbox row (with its deterministic
 * idempotency key) before the HTTP call, never after.
 *
 * What this class deliberately does NOT do: decide what a message says. Every
 * argument to enqueue() — subject, rendered HTML, idempotency key, priority — is
 * the caller's responsibility (E1.10's job); this class only guarantees the
 * outbox/HTTP-call ordering and records what happened.
 */
final class CentralMailerOutboxService
{
    public function __construct(
        private readonly CentralMailerClientInterface $client,
        private readonly CentralMailerOutboxRepository $outbox,
    ) {
    }

    /**
     * @param array{
     *   sourceEntityType: string,
     *   sourceEntityId?: ?string,
     *   idempotencyKey: string,
     *   priority?: string,
     *   to: string,
     *   subject: string,
     *   html: string,
     *   text?: ?string,
     *   contextId?: ?string,
     *   metadata?: ?array<string, mixed>,
     * } $message
     * @return array<string, mixed> the outbox row (existing, if this idempotency
     *         key was already recorded — nothing is sent twice)
     */
    public function enqueue(array $message): array
    {
        $idempotencyKey = $message['idempotencyKey'];

        // The idempotency key is the true "have we done this before" check —
        // checked here, against our own durable record, before Central Mailer
        // is ever called. This is stronger than relying on Central Mailer's own
        // idempotent-replay behavior (§6.1): it also covers the case where our
        // process died after writing the outbox row but before the HTTP call's
        // response came back (see CentralMailerOutboxRepository::create()).
        $existing = $this->outbox->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $priority = $message['priority'] ?? 'normal';

        $row = $this->outbox->create([
            'sourceEntityType' => $message['sourceEntityType'],
            'sourceEntityId' => $message['sourceEntityId'] ?? null,
            'idempotencyKey' => $idempotencyKey,
            'priority' => $priority,
            'recipient' => $message['to'],
            'subject' => $message['subject'],
            'html' => $message['html'],
            'contextId' => $message['contextId'] ?? null,
            'metadata' => $message['metadata'] ?? null,
        ]);

        try {
            $result = $this->client->enqueue([
                'to' => $message['to'],
                'subject' => $message['subject'],
                'html' => $message['html'],
                'text' => $message['text'] ?? null,
                'priority' => $priority,
                'contextId' => $message['contextId'] ?? null,
                'metadata' => $message['metadata'] ?? null,
            ], $idempotencyKey);
        } catch (\Throwable $e) {
            $this->outbox->markFailed($row['id'], $e->getMessage());
            throw $e;
        }

        $this->outbox->markAccepted($row['id'], $result['id'], $result['status']);

        return $this->outbox->find($row['id']);
    }

    /**
     * E1.10 §8 mass-send progress: queued/sent/failed counts for one mail type
     * within one Wydarzenie (its `contextId`), so an organizer sending tickets
     * to 500+ recipients sees a progress readout instead of silence during the
     * ~75-90 minutes the shared 100/15min budget takes (kontrakt-central-mailer.md
     * §7). No queue-worker/cron here (this repo has none) — the caller is
     * expected to poll this on demand, same lazy-check shape as reconcile().
     *
     * @return array{total: int, queued: int, sent: int, failed: int, other: int}
     */
    public function progressForContext(string $contextId, string $sourceEntityType): array
    {
        return $this->outbox->countByContext($contextId, $sourceEntityType);
    }

    /**
     * Polls Central Mailer for every outbox row still awaiting a terminal
     * status and writes back whatever it reports (§6.4 point 3 — Central Mailer
     * has no webhooks, only polling). This repo has no cron infrastructure
     * (mirroring how E0.8/E1.1/E1.4 handled that: lazy checks rather than a
     * scheduled job), so this is a plain method meant to be called from
     * wherever needs fresh status — an admin/status endpoint, or a lazy check on
     * read, per the ticket. There is deliberately no automatic trigger here.
     *
     * @return array{checked: int, updated: int}
     */
    public function reconcile(int $limit = 100): array
    {
        $rows = $this->outbox->listAwaitingReconciliation($limit);
        $updated = 0;

        foreach ($rows as $row) {
            $emailId = (string) $row['centralMailerEmailId'];
            try {
                $status = $this->client->getStatus($emailId);
            } catch (\Throwable) {
                // A single unreachable/erroring row must not abort the rest of
                // the batch; it simply keeps its last known status and will be
                // retried on the next call.
                continue;
            }

            $newStatus = (string) ($status['status'] ?? '');
            if ($newStatus === '') {
                continue;
            }

            $lastError = $status['lastError'] ?? null;
            $this->outbox->applyReconciledStatus($row['id'], $newStatus, $lastError !== null ? (string) $lastError : null);
            $updated++;
        }

        return ['checked' => count($rows), 'updated' => $updated];
    }
}
