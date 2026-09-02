<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

/**
 * Client-zone contract of Central Mailer's HTTP API (kontrakt-central-mailer.md
 * §1.2), scoped to what E1.9's technical integration layer needs: enqueue one
 * message, and look its status up afterward for reconciliation (§6.4, §8.1).
 * Deliberately excludes `/emails/batch` (out of scope per the ticket — single
 * enqueue is the priority) and everything under `/admin/*` (a different key,
 * §1.3, that this platform must never hold — see CentralMailerConfig's doc
 * comment).
 */
interface CentralMailerClientInterface
{
    /**
     * POST /emails — enqueue exactly one message. `$idempotencyKey`, when given,
     * is sent as the `Idempotency-Key` header; constructing a *deterministic* key
     * is entirely the caller's responsibility (this client never invents one —
     * see source doc §6.2 on why `random_bytes()`/`uniqid()`/timestamps in a key
     * defeat idempotency instead of providing it).
     *
     * @param array{
     *   to: string,
     *   subject: string,
     *   html: string,
     *   text?: ?string,
     *   priority?: string,
     *   category?: ?string,
     *   contextId?: ?string,
     *   metadata?: ?array<string, mixed>,
     *   attachments?: array<int, array<string, mixed>>,
     * } $email
     * @return array{id: string, status: string}
     *
     * @throws IdempotencyConflictException the same Idempotency-Key was already
     *         used for a message with different content (HTTP 409, §6.1) — a
     *         strong signal the caller's HTML rendering was not deterministic.
     * @throws RecipientSuppressedException the recipient is on Central Mailer's
     *         suppression list (HTTP 422, §1.2) — undocumented in its own OpenAPI.
     */
    public function enqueue(array $email, ?string $idempotencyKey = null): array;

    /**
     * GET /emails/{id} — full status payload for one message (§1.2, §8.1). Used
     * for reconciliation: Central Mailer has no webhooks (§8.2), so this is the
     * only way to learn a message's fate after enqueueing it.
     *
     * @return array<string, mixed>
     */
    public function getStatus(string $centralMailerEmailId): array;

    /**
     * GET /emails/context/{contextId} — statuses for every message sharing one
     * `contextId` (§1.2). An unknown context returns an empty result, not 404.
     *
     * @return array<string, mixed>
     */
    public function getStatusByContext(string $contextId, int $limit = 500, int $offset = 0): array;
}
