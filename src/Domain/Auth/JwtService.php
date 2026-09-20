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
}
