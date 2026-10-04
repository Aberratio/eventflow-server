<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use PDO;

/**
 * PP.70: the same row also says whether the account is still `active`, so a
 * blocked account has no valid session even if its status was changed outside
 * the staff routes (which also bump the token version).
 *
 * PP.7: one primary-key lookup per authenticated request. A PDOException is
 * deliberately not caught: the guard fails closed (500), never open.
 *
 * A token without `tv` counts as version 0 and one without `jti` cannot match a
 * revocation row, so sessions issued before this feature stay valid until their
 * normal expiry (no mass logout at deploy).
 */
final class DbSessionGuard implements SessionGuard
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isSessionValid(array $claims): bool
    {
        $jti = $claims['jti'] ?? null;
        $stmt = $this->pdo->prepare(
            'SELECT u.token_version AS tv, u.status AS status,
                    (SELECT COUNT(*) FROM revoked_session_tokens r WHERE r.jti = :jti) AS revoked
             FROM users u WHERE u.id = :id'
        );
        $stmt->execute([
            'jti' => is_string($jti) ? $jti : '',
            'id' => (string) ($claims['sub'] ?? ''),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        if ((string) $row['status'] !== 'active') {
            return false;
        }
        if ((int) ($claims['tv'] ?? 0) !== (int) $row['tv']) {
            return false;
        }

        return (int) $row['revoked'] === 0;
    }
}
