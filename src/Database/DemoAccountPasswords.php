<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use ZmierzymyCzas\Domain\Auth\PasswordRule;
use ZmierzymyCzas\Domain\Users\UserRepository;

/**
 * PP.90: demo accounts (seeders, /dev/seed-event) carry well-known passwords (`demo1234`, `demo123`).
 * Outside local they must not: scrub() gives each of them a fresh unusable password hash and bumps
 * token_version, which also ends every live session of the account.
 *
 * Targets: every user recorded in the seed manifest, plus every user whose hash is still the demo hash
 * of the SQL seeders. With $deep it also verifies every other account against the two demo passwords
 * (bcrypt per row, for the one-off cleanup of accounts created before the manifest tracked them).
 */
final class DemoAccountPasswords
{
    /**
     * The bcrypt of `demo1234` hard-coded in database/seeders/001_demo.sql and generate_events.php
     * (the seeders are locked and not autoloadable, so the literal is repeated here).
     */
    public const KNOWN_DEMO_HASHES = ['$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK'];

    private const DEMO_PASSWORDS = ['demo123', 'demo1234'];

    /**
     * @param bool $dryRun count the accounts that would change, write nothing
     * @param callable(int): void|null $progress called with the number of rows checked so far (deep mode)
     * @return int number of accounts scrubbed (or that would be)
     */
    public static function scrub(PDO $pdo, bool $deep = false, bool $dryRun = false, ?callable $progress = null): int
    {
        $selected = self::targets($pdo, $deep, $progress);

        if (!$dryRun) {
            // One hash for the whole run: bcrypt per row would take minutes for thousands of demo accounts, and
            // nobody knows the secret behind it, so sharing it between the scrubbed accounts reveals nothing.
            self::setHash($pdo, $selected, UserRepository::unusablePasswordHash());
        }

        return count($selected);
    }

    /**
     * Sets $password on the accounts scrub() would target (non-deep): seed-manifest users and users still
     * carrying the seeders' demo hash. Used by bin/seed.php outside local when SEED_DEMO_PASSWORD is set, so
     * dev/staging get demo logins with a secret that lives only in that server's .env, never in the repo.
     * Bumps token_version like scrub() does.
     *
     * @return int number of accounts updated
     * @throws \InvalidArgumentException when the password breaks PasswordRule or is a well-known demo password
     */
    public static function assign(PDO $pdo, string $password): int
    {
        $violation = PasswordRule::violation($password, null);
        if ($violation !== null) {
            throw new \InvalidArgumentException($violation['message']);
        }
        if (in_array(strtolower($password), self::DEMO_PASSWORDS, true)) {
            throw new \InvalidArgumentException('The well-known demo password is not allowed outside local.');
        }

        $selected = self::targets($pdo, false, null);
        // One hash for the whole run, as in scrub(): every target shares the same password anyway.
        self::setHash($pdo, $selected, password_hash($password, PASSWORD_DEFAULT));

        return count($selected);
    }

    /**
     * @param callable(int): void|null $progress
     * @return list<string>
     */
    private static function targets(PDO $pdo, bool $deep, ?callable $progress): array
    {
        $targets = array_fill_keys(self::manifestUserIds($pdo), true);

        $rows = $pdo->query('SELECT id, password_hash FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $checked = 0;
        $selected = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $hash = (string) $row['password_hash'];
            if (isset($targets[$id]) || in_array($hash, self::KNOWN_DEMO_HASHES, true)) {
                $selected[] = $id;
                continue;
            }
            if (!$deep) {
                continue;
            }
            foreach (self::DEMO_PASSWORDS as $password) {
                if (password_verify($password, $hash)) {
                    $selected[] = $id;
                    break;
                }
            }
            $checked++;
            if ($progress !== null && $checked % 500 === 0) {
                $progress($checked);
            }
        }

        return $selected;
    }

    /** @param list<string> $ids */
    private static function setHash(PDO $pdo, array $ids, string $hash): void
    {
        $update = $pdo->prepare('UPDATE users SET password_hash = :hash, token_version = token_version + 1 WHERE id = :id');
        foreach ($ids as $id) {
            $update->execute(['hash' => $hash, 'id' => $id]);
        }
    }

    /** @return list<string> */
    private static function manifestUserIds(PDO $pdo): array
    {
        $exists = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $exists->execute([SeedManifest::TABLE]);
        if ((int) $exists->fetchColumn() === 0) {
            return [];
        }

        $stmt = $pdo->prepare('SELECT record_key FROM ' . SeedManifest::TABLE . ' WHERE table_name = ?');
        $stmt->execute(['users']);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $decoded = json_decode((string) $key, true);
            $ids[] = is_array($decoded) ? (string) ($decoded[0] ?? '') : (string) $key;
        }

        return $ids;
    }
}
