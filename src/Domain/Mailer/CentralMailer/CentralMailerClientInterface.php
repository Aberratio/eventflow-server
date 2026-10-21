<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CentralMailer;

/**
 * Client-zone contract of Central Mailer's HTTP API,
 * scoped to what the technical integration layer needs: enqueue one
 * message, and look its status up afterward for reconciliation.
 * Deliberately excludes `/emails/batch` (out of scope — single
 * enqueue is the priority) and everything under `/admin/*` (a different key,
 * that this platform must never hold — see CentralMailerConfig's doc
 * comment).
 */
interface CentralMailerClientInterface
{
    /**
     * POST /emails — enqueue exactly one message. `$idempotencyKey`, when given,
     * is sent as the `Idempotency-Key` header; constructing a *deterministic* key
     * is entirely the caller's responsibility (this client never invents one —
     * see the API contract on why `random_bytes()`/`uniqid()`/timestamps in a key
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
     *   replyTo?: ?array{email: string, name?: ?string},
     * } $email
     * @return array{id: string, status: string}
     *
     * @throws IdempotencyConflictException the same Idempotency-Key was already
     *         used for a message with different content (HTTP 409) — a
     *         strong signal the caller's HTML rendering was not deterministic.
     * @throws RecipientSuppressedException the recipient is on Central Mailer's
     *         suppression list (HTTP 422) — undocumented in its own OpenAPI.
     * @throws RateLimitedException HTTP 429; carries the Retry-After seconds when sent.
     * @throws CentralMailerRejectedException HTTP 400/413: a permanent rejection.
     */
    public function enqueue(array $email, ?string $idempotencyKey = null): array;

    /**
     * GET /emails/{id} — full status payload for one message. Used
     * for reconciliation: Central Mailer has no webhooks, so this is the
     * only way to learn a message's fate after enqueueing it.
     *
     * @return array<string, mixed>
     */
    public function getStatus(string $centralMailerEmailId): array;

    /**
     * GET /emails/context/{contextId} — statuses for every message sharing one
     * `contextId`. An unknown context returns an empty result, not 404.
     *
     * @return array<string, mixed>
     */
    public function getStatusByContext(string $contextId, int $limit = 500, int $offset = 0): array;
}
