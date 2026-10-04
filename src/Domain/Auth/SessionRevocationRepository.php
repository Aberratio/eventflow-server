<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use PDO;

/** PP.7: deny-list of logged-out session ids (`jti`), kept only until the token would have expired anyway. */
final class SessionRevocationRepository
{
    private const MARGIN_SECONDS = 3600;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param int $ttlSeconds remaining lifetime of the token; the row is kept for that long plus a margin.
     *                        Expiry is computed with the DB clock (NOW()) so purging never depends on PHP/DB timezone skew.
     */
    public function revoke(string $jti, string $userId, int $ttlSeconds): void
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO revoked_session_tokens (jti, user_id, expires_at)
             VALUES (:jti, :userId, DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
        )->execute(['jti' => $jti, 'userId' => $userId, 'ttl' => max(0, $ttlSeconds) + self::MARGIN_SECONDS]);
    }

    public function purgeExpired(): void
    {
        $this->pdo->exec('DELETE FROM revoked_session_tokens WHERE expires_at < NOW() LIMIT 500');
    }
}
