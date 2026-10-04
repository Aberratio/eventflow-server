<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtService
{
    /**
     * firebase/php-jwt >=7.0 rejects HS256 keys shorter than 256 bits (32
     * bytes) at sign/verify time (CVE-2025-45769 fix). Validate here too so
     * misconfiguration fails fast with a clear message pointing at JWT_SECRET,
     * rather than surfacing as an opaque DomainException from inside the
     * library on the first login attempt.
     */
    private const MIN_SECRET_BYTES = 32;

    private ?SessionGuard $sessionGuard = null;

    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds
    ) {
        if (\strlen($this->secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'JWT secret must be at least %d bytes long for HS256 (got %d); check JWT_SECRET.',
                self::MIN_SECRET_BYTES,
                \strlen($this->secret)
            ));
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims): string
    {
        $now = time();
        return JWT::encode([
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            ...$claims,
        ], $this->secret, 'HS256');
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        return (array) JWT::decode($token, new Key($this->secret, 'HS256'));
    }

    /** Returns a copy that also runs `$guard` in decodeSession(). */
    public function withSessionGuard(SessionGuard $guard): self
    {
        $copy = clone $this;
        $copy->sessionGuard = $guard;

        return $copy;
    }

    /**
     * Decodes a token that is presented as a login session: a valid signature
     * and expiry (decode()), no `purpose` claim (those are one-time mail-link
     * tokens, never sessions), a non-empty string `sub`, and the session guard
     * when one is set.
     *
     * @return array<string, mixed>
     * @throws \UnexpectedValueException when the token is not an acceptable session
     */
    public function decodeSession(string $token): array
    {
        $claims = $this->decode($token);
        if (array_key_exists('purpose', $claims)) {
            throw new \UnexpectedValueException('Not a session token.');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new \UnexpectedValueException('Session token has no subject.');
        }
        if ($this->sessionGuard !== null && !$this->sessionGuard->isSessionValid($claims)) {
            throw new \UnexpectedValueException('Session is no longer valid.');
        }

        return $claims;
    }

    public static function newJti(): string
    {
        return bin2hex(random_bytes(16));
    }
}
