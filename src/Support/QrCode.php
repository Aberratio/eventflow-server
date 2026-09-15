<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

/**
 * The two QR tokens of spec 11 ("Tozsamosc zawodnika i kody QR"), in one place.
 *
 * Spec §3: both codes have an identical syntactic shape and differ only by prefix —
 * `pqr_` for the kod startowy, which belongs to a Zapis (E1.11), and `zqr_` for the
 * kod tozsamosci, which belongs to a Zawodnik (E1.12). The shape is bit-for-bit what
 * Biuro Zawodow already generates for itself (`biuro_zawodow-api/src/QrCodeService.php`:
 * prefix + 48 hex chars from `random_bytes(24)`), so BZ's existing validation regex and
 * its offline scanner need no changes. What the platform took over is *who* generates
 * them, never the format.
 *
 * Centralised because the format had drifted into five separate copies of the same
 * `bin2hex(random_bytes(24))` expression — the two repositories that create rows, the
 * two migration backfills, and the fixture importer. That is the same failure mode as
 * the bug that produced this class: a format that lives in five places is a format
 * someone will change in four. A code generated here is indistinguishable from one the
 * runtime creates, which is exactly what a backfill needs.
 *
 * `random_bytes()` and nothing else: these are identity tokens whose only protection is
 * unguessability. 24 bytes is 192 bits from a cryptographically secure source. Neither
 * MariaDB's `UUID()` (v1 — time plus MAC address) nor `RAND()` qualifies, which is why
 * both backfills had to leave SQL in the first place.
 */
final class QrCode
{
    /** Kod startowy — nalezy do Zapisu (E1.11). */
    public const START_PREFIX = 'pqr_';

    /** Kod tozsamosci — nalezy do Zawodnika (E1.12). */
    public const IDENTITY_PREFIX = 'zqr_';

    /** Entropy in bytes; 24 -> 48 hex characters, matching BZ's own generator. */
    private const BYTES = 24;

    public static function startCode(): string
    {
        return self::token(self::START_PREFIX);
    }

    public static function identityCode(): string
    {
        return self::token(self::IDENTITY_PREFIX);
    }

    /**
     * The regex BZ validates with, for the prefix given — used by tests and by
     * anything that has to recognise a code without generating one.
     */
    public static function pattern(string $prefix): string
    {
        return '/^' . preg_quote($prefix, '/') . '[a-f0-9]{' . (self::BYTES * 2) . '}$/';
    }

    private static function token(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(self::BYTES));
    }
}
