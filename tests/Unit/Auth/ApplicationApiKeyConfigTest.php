<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Auth\ApplicationApiKeyConfig;

final class ApplicationApiKeyConfigTest extends TestCase
{
    public function testValidKeyResolvesToItsApplication(): void
    {
        $config = new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']);

        self::assertSame('biuro_zawodow', $config->resolveApplication('secret-bz'));
    }

    public function testUnknownKeyResolvesToNull(): void
    {
        $config = new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']);

        self::assertNull($config->resolveApplication('not-a-configured-key'));
    }

    public function testEmptyKeyResolvesToNull(): void
    {
        $config = new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']);

        self::assertNull($config->resolveApplication(''));
    }

    public function testMalformedKeyResolvesToNull(): void
    {
        $config = new ApplicationApiKeyConfig(['biuro_zawodow' => 'secret-bz']);

        self::assertNull($config->resolveApplication("secret-bz\0trailing-garbage"));
    }

    public function testNoApplicationsConfiguredFailsClosedForAnyKey(): void
    {
        // The critical asymmetry vs TpayConfig/PayNowConfig/CentralMailerConfig:
        // those treat "unset" as "skip the outgoing call" (safe). This class
        // gates INCOMING access, so "no keys configured at all" (empty/unset
        // .env — local dev, CI) must reject every request, never accept
        // everything.
        $config = new ApplicationApiKeyConfig([]);

        self::assertNull($config->resolveApplication('anything'));
        self::assertNull($config->resolveApplication(''));
    }

    public function testTwoDistinctApplicationsResolveToDistinctIdentitiesWithoutConfusion(): void
    {
        $config = new ApplicationApiKeyConfig([
            'biuro_zawodow' => 'secret-bz',
            'other_app' => 'secret-other',
        ]);

        self::assertSame('biuro_zawodow', $config->resolveApplication('secret-bz'));
        self::assertSame('other_app', $config->resolveApplication('secret-other'));

        // Neither application's key must authenticate as the other, nor as a
        // key built by mixing the two.
        self::assertNull($config->resolveApplication('secret-o'));
        self::assertNull($config->resolveApplication('secret-bzsecret-other'));
    }

    public function testFromEnvDefaultsToUnconfiguredWhenUnset(): void
    {
        // Mirrors TpayConfig/PayNowConfig/CentralMailerConfig::fromEnv() tests:
        // this repo's own test suite/CI never sets INCOMING_API_KEYS, so
        // fromEnv() must produce a config that fails closed.
        $config = ApplicationApiKeyConfig::fromEnv();

        self::assertNull($config->resolveApplication('anything'));
    }

    public function testFromEnvParsesCommaSeparatedApplicationKeyPairs(): void
    {
        putenv('INCOMING_API_KEYS=biuro_zawodow=secret-bz,other_app=secret-other');

        try {
            $config = ApplicationApiKeyConfig::fromEnv();

            self::assertSame('biuro_zawodow', $config->resolveApplication('secret-bz'));
            self::assertSame('other_app', $config->resolveApplication('secret-other'));
        } finally {
            putenv('INCOMING_API_KEYS');
        }
    }

    public function testFromEnvIgnoresBlankAndMalformedEntries(): void
    {
        putenv('INCOMING_API_KEYS=, biuro_zawodow=secret-bz , no-equals-sign, =missing-app, empty_key=');

        try {
            $config = ApplicationApiKeyConfig::fromEnv();

            self::assertSame('biuro_zawodow', $config->resolveApplication('secret-bz'));
        } finally {
            putenv('INCOMING_API_KEYS');
        }
    }
}
