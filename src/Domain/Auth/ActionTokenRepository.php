<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use PDO;
use ZmierzymyCzas\Domain\Repository;

/**
 * Opaque one-time tokens for mail links (password reset, e-mail verification,
 * e-mail change). Only sha256(token) is stored. Raw tokens are never logged, never
 * returned in an API response and never stored. All time comparisons use the DB clock
 * (NOW()) for writes and reads alike, so there is no PHP/DB timezone skew.
 */
final class ActionTokenRepository extends Repository
{
    /**
     * @return array{id: string, raw: string} raw is what goes into the mail link
     */
    public function issue(string $userId, string $purpose, int $ttlSeconds, ?string $newEmail = null): array
    {
        $id = $this->id();
        $raw = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO user_action_tokens (id, user_id, purpose, token_hash, new_email, expires_at)
             VALUES (:id, :userId, :purpose, :hash, :newEmail, DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
        )->execute([
            'id' => $id,
            'userId' => $userId,
            'purpose' => $purpose,
            'hash' => hash('sha256', $raw),
            'newEmail' => $newEmail,
            'ttl' => $ttlSeconds,
        ]);

        return ['id' => $id, 'raw' => $raw];
    }

    /**
     * Atomically uses up a token. Null for anything that is not a currently valid, unused token
     * of that purpose belonging to an existing user (unknown, malformed, wrong purpose, used,
     * expired, deleted user): callers must not tell these apart.
     *
     * @return array{id: string, userId: string, newEmail: ?string}|null
     */
    public function consume(string $raw, string $purpose): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $raw) !== 1) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.user_id, t.new_email
             FROM user_action_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash AND t.purpose = :purpose AND t.used_at IS NULL AND t.expires_at > NOW()'
        );
        $stmt->execute(['hash' => hash('sha256', $raw), 'purpose' => $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Race-safe: of two concurrent consumers only the one that flips used_at wins.
        $claim = $this->pdo->prepare('UPDATE user_action_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
        $claim->execute(['id' => $row['id']]);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'userId' => (string) $row['user_id'],
            'newEmail' => $row['new_email'] === null ? null : (string) $row['new_email'],
        ];
    }

    /** Marks every still-open token of that purpose as used, so only the newest link works. */
    public function invalidateOpen(string $userId, string $purpose): void
    {
        $this->pdo->prepare(
            'UPDATE user_action_tokens SET used_at = NOW() WHERE user_id = :userId AND purpose = :purpose AND used_at IS NULL'
        )->execute(['userId' => $userId, 'purpose' => $purpose]);
    }

    /** Tokens issued for that user and purpose within the last $seconds (cooldown / hourly cap). */
    public function countRecent(string $userId, string $purpose, int $seconds): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_action_tokens
             WHERE user_id = :userId AND purpose = :purpose AND created_at > DATE_SUB(NOW(), INTERVAL :seconds SECOND)'
        );
        $stmt->execute(['userId' => $userId, 'purpose' => $purpose, 'seconds' => $seconds]);

        return (int) $stmt->fetchColumn();
    }
}
