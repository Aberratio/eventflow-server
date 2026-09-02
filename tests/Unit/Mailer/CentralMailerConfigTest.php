<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;

final class CentralMailerConfigTest extends TestCase
{
    public function testIsConfiguredFalseWhenEitherValueMissing(): void
    {
        self::assertFalse((new CentralMailerConfig('', ''))->isConfigured());
        self::assertFalse((new CentralMailerConfig('key', ''))->isConfigured());
        self::assertFalse((new CentralMailerConfig('', 'https://mailer.example.test'))->isConfigured());
    }

    public function testIsConfiguredTrueWhenBothPresent(): void
    {
        self::assertTrue((new CentralMailerConfig('key', 'https://mailer.example.test'))->isConfigured());
    }

    public function testFromEnvDefaultsToUnconfiguredPlaceholders(): void
    {
        // No CENTRAL_MAILER_* set in this test process's environment (mirrors
        // TpayConfig/PayNowConfig — dev/CI/this repo's own test suite never set
        // real credentials, so RegistrationRepository-style callers must treat
        // "not configured" as "skip sending" rather than attempting a call).
        $config = CentralMailerConfig::fromEnv();

        self::assertFalse($config->isConfigured());
    }

    public function testFromEnvStripsTrailingSlashFromBaseUrl(): void
    {
        putenv('CENTRAL_MAILER_API_KEY=k');
        putenv('CENTRAL_MAILER_BASE_URL=https://mailer.example.test/');

        try {
            $config = CentralMailerConfig::fromEnv();
            self::assertSame('https://mailer.example.test', $config->baseUrl);
        } finally {
            putenv('CENTRAL_MAILER_API_KEY');
            putenv('CENTRAL_MAILER_BASE_URL');
        }
    }
}
