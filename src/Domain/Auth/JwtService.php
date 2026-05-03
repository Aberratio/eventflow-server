<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds
    ) {
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
