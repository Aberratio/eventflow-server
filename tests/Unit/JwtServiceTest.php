<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use Firebase\JWT\ExpiredException;
use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Auth\JwtService;
use ZmierzymyCzas\Domain\Auth\SessionGuard;

final class JwtServiceTest extends TestCase
{
    public function testItIssuesAndDecodesToken(): void
    {
        $service = new JwtService('test-secret-at-least-32-bytes-long', 3600);

        $token = $service->issue(['sub' => 'u-admin', 'role' => 'admin']);
        $claims = $service->decode($token);

        self::assertSame('u-admin', $claims['sub']);
        self::assertSame('admin', $claims['role']);
        self::assertArrayHasKey('exp', $claims);
    }

    public function testConstructorRejectsSecretShorterThan32Bytes(): void
    {
        // firebase/php-jwt >=7.0 (CVE-2025-45769 fix) refuses HS256 keys
        // below 256 bits; JwtService now fails fast on construction instead
        // of surfacing the library's DomainException on first token issue.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        new JwtService('31-bytes-exactly-is-too-short-!', 3600);
    }

    public function testConstructorAcceptsSecretOfExactly32Bytes(): void
    {
        $secret = str_repeat('a', 32);
        self::assertSame(32, strlen($secret));

        $service = new JwtService($secret, 3600);
        $token = $service->issue(['sub' => 'u-boundary']);

        self::assertSame('u-boundary', $service->decode($token)['sub']);
    }

    public function testDecodeRejectsTokenSignedWithADifferentSecret(): void
    {
        $issuer = new JwtService('first-secret-that-is-32-bytes!!!', 3600);
        $verifier = new JwtService('second-secret-that-is-32-bytes!!', 3600);

        $token = $issuer->issue(['sub' => 'u-admin']);

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        $verifier->decode($token);
    }

    public function testDecodeRejectsExpiredToken(): void
    {
        $service = new JwtService('test-secret-at-least-32-bytes-long', -1);

        $token = $service->issue(['sub' => 'u-admin']);

        $this->expectException(ExpiredException::class);
        $service->decode($token);
    }

    public function testDecodeSessionRejectsPurposeClaim(): void
    {
        $service = new JwtService('test-secret-at-least-32-bytes-long', 3600);
        $token = $service->issue(['sub' => 'u-1', 'purpose' => 'email_verification']);

        $this->expectException(\UnexpectedValueException::class);
        $service->decodeSession($token);
    }

    public function testDecodeSessionRejectsMissingSub(): void
    {
        $service = new JwtService('test-secret-at-least-32-bytes-long', 3600);

        foreach ([['role' => 'admin'], ['sub' => '', 'role' => 'admin'], ['sub' => 123, 'role' => 'admin']] as $claims) {
            try {
                $service->decodeSession($service->issue($claims));
                self::fail('A token without a non-empty string sub must not be a session.');
            } catch (\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDecodeSessionConsultsGuard(): void
    {
        $base = new JwtService('test-secret-at-least-32-bytes-long', 3600);
        $token = $base->issue(['sub' => 'u-1', 'role' => 'admin']);

        $deny = $base->withSessionGuard(new class implements SessionGuard {
            public function isSessionValid(array $claims): bool
            {
                return false;
            }
        });
        $allow = $base->withSessionGuard(new class implements SessionGuard {
            public function isSessionValid(array $claims): bool
            {
                return true;
            }
        });

        try {
            $deny->decodeSession($token);
            self::fail('A rejecting guard must make decodeSession throw.');
        } catch (\UnexpectedValueException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('u-1', $allow->decodeSession($token)['sub']);
        // withSessionGuard clones: the original service stays guard-free.
        self::assertSame('u-1', $base->decodeSession($token)['sub']);
    }

    public function testDecodeStillAcceptsPurpose(): void
    {
        $service = new JwtService('test-secret-at-least-32-bytes-long', 3600);
        $claims = $service->decode($service->issue(['sub' => 'u-1', 'purpose' => 'password_reset']));

        self::assertSame('password_reset', $claims['purpose']);
    }

    public function testNewJtiIs32Hex(): void
    {
        $a = JwtService::newJti();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        self::assertNotSame($a, JwtService::newJti());
    }
}
