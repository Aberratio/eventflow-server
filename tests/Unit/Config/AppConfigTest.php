<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Config\AppConfig;
use ZmierzymyCzas\Config\ConfigurationException;

final class AppConfigTest extends TestCase
{
    /**
     * @return array<string, string|null>
     */
    private function validProduction(): array
    {
        return [
            'APP_ENV' => AppConfig::ENV_PRODUCTION,
            'APP_DEBUG' => 'false',
            'JWT_SECRET' => str_repeat('k', 64),
            'JWT_TTL_SECONDS' => null,
            'APP_CORS_ORIGIN' => 'https://zawody.pl,https://www.zawody.pl',
            'TPAY_SANDBOX' => 'false',
            'PAYNOW_SANDBOX' => 'false',
        ];
    }

    public function testValidProductionConfigIsAccepted(): void
    {
        $config = AppConfig::fromEnvironment($this->validProduction());

        self::assertSame(['https://zawody.pl', 'https://www.zawody.pl'], $config->corsOrigins);
        self::assertTrue($config->isProduction());
    }

    /**
     * @dataProvider missingJwtSecretProvider
     */
    public function testMissingJwtSecretIsRejectedInEveryEnvironment(?string $appEnv, ?string $jwtSecret): void
    {
        $env = $this->validProduction();
        if ($appEnv === null) {
            unset($env['APP_ENV']);
        } else {
            $env['APP_ENV'] = $appEnv;
        }
        $env['JWT_SECRET'] = $jwtSecret;

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsStringIgnoringLineEndings(
                'JWT_SECRET is not set',
                implode(' ', $e->errors())
            );
        }
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function missingJwtSecretProvider(): iterable
    {
        foreach ([AppConfig::ENV_LOCAL, AppConfig::ENV_STAGING, AppConfig::ENV_PRODUCTION, null] as $env) {
            $label = $env ?? 'unset';
            yield "$label / missing key" => [$env, null];
            yield "$label / empty string" => [$env, ''];
        }
    }

    public function testJwtSecretShorterThan32BytesIsRejected(): void
    {
        $env = $this->validProduction();
        $secret = str_repeat('a', 31);
        $env['JWT_SECRET'] = $secret;

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            $joined = implode(' ', $e->errors());
            self::assertStringContainsString('(got 31)', $joined);
            self::assertStringNotContainsString($secret, $joined);
        }
    }

    public function testJwtSecretOfExactly32BytesIsAccepted(): void
    {
        $env = $this->validProduction();
        $env['JWT_SECRET'] = str_repeat('a', 32);

        $config = AppConfig::fromEnvironment($env);

        self::assertSame(str_repeat('a', 32), $config->jwtSecret);
    }

    /**
     * @dataProvider placeholderSecretProvider
     */
    public function testPlaceholderSecretIsRejectedOutsideLocal(string $secret, string $appEnv): void
    {
        $env = $this->validProduction();
        $env['APP_ENV'] = $appEnv;
        $env['JWT_SECRET'] = $secret;
        if ($appEnv === AppConfig::ENV_STAGING) {
            $env['APP_CORS_ORIGIN'] = 'http://localhost:8080';
        }

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('placeholder', implode(' ', $e->errors()));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function placeholderSecretProvider(): iterable
    {
        $placeholders = [
            'change-me-please-32-bytes-min!!!',
            'change-me-in-production-min-32-bytes',
            'Change-Me-anything-' . str_repeat('x', 21),
        ];
        foreach ($placeholders as $secret) {
            foreach ([AppConfig::ENV_STAGING, AppConfig::ENV_PRODUCTION] as $env) {
                yield "$secret / $env" => [$secret, $env];
            }
        }
    }

    public function testPlaceholderSecretIsAcceptedLocally(): void
    {
        $env = [
            'APP_ENV' => AppConfig::ENV_LOCAL,
            'JWT_SECRET' => 'change-me-in-production-min-32-bytes',
        ];

        $config = AppConfig::fromEnvironment($env);

        self::assertSame('change-me-in-production-min-32-bytes', $config->jwtSecret);
    }

    public function testProductionRefusesDebug(): void
    {
        $env = $this->validProduction();
        $env['APP_DEBUG'] = 'true';

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('APP_DEBUG=true is not allowed', implode(' ', $e->errors()));
        }
    }

    /**
     * @dataProvider productionSandboxProvider
     */
    public function testProductionRefusesSandboxFlags(string $key, ?string $value): void
    {
        $env = $this->validProduction();
        if ($value === null) {
            unset($env[$key]);
        } else {
            $env[$key] = $value;
        }

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('must be explicitly false', implode(' ', $e->errors()));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function productionSandboxProvider(): iterable
    {
        foreach (['TPAY_SANDBOX', 'PAYNOW_SANDBOX'] as $key) {
            yield "$key true" => [$key, 'true'];
            yield "$key unset" => [$key, null];
        }
    }

    public function testStagingAllowsSandboxAndHttpOrigin(): void
    {
        $env = [
            'APP_ENV' => AppConfig::ENV_STAGING,
            'JWT_SECRET' => str_repeat('k', 64),
            'APP_CORS_ORIGIN' => 'http://localhost:8080',
        ];

        $config = AppConfig::fromEnvironment($env);

        self::assertTrue($config->tpaySandbox);
        self::assertTrue($config->paynowSandbox);
        self::assertSame(['http://localhost:8080'], $config->corsOrigins);
    }

    public function testUnsetAppEnvUsesProductionRules(): void
    {
        $env = $this->validProduction();
        unset($env['APP_ENV']);
        $env['APP_DEBUG'] = 'true';

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('APP_DEBUG=true is not allowed', implode(' ', $e->errors()));
        }
    }

    public function testUnknownAppEnvIsRejected(): void
    {
        $env = $this->validProduction();
        $env['APP_ENV'] = 'prod';

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('APP_ENV must be one of', implode(' ', $e->errors()));
        }
    }

    /**
     * @dataProvider corsRequiredEnvProvider
     */
    public function testCorsOriginIsRequiredOutsideLocal(string $appEnv): void
    {
        $env = $this->validProduction();
        $env['APP_ENV'] = $appEnv;
        unset($env['APP_CORS_ORIGIN']);

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('APP_CORS_ORIGIN is required', implode(' ', $e->errors()));
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function corsRequiredEnvProvider(): iterable
    {
        yield 'staging' => [AppConfig::ENV_STAGING];
        yield 'production' => [AppConfig::ENV_PRODUCTION];
    }

    public function testLocalWithoutCorsOriginFallsBackToDevOrigins(): void
    {
        $env = [
            'APP_ENV' => AppConfig::ENV_LOCAL,
            'JWT_SECRET' => str_repeat('k', 64),
        ];

        $config = AppConfig::fromEnvironment($env);

        self::assertSame(AppConfig::LOCAL_DEV_ORIGINS, $config->corsOrigins);
    }

    /**
     * @dataProvider wildcardCorsProvider
     */
    public function testWildcardCorsIsRejectedOutsideLocal(string $appEnv, string $origin): void
    {
        $env = $this->validProduction();
        $env['APP_ENV'] = $appEnv;
        $env['APP_CORS_ORIGIN'] = $origin;

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("must not contain '*'", implode(' ', $e->errors()));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function wildcardCorsProvider(): iterable
    {
        foreach ([AppConfig::ENV_STAGING, AppConfig::ENV_PRODUCTION] as $env) {
            yield "$env / *" => [$env, '*'];
            yield "$env / mixed" => [$env, 'https://a.pl,*'];
        }
    }

    public function testWildcardCorsIsAllowedLocally(): void
    {
        $env = [
            'APP_ENV' => AppConfig::ENV_LOCAL,
            'JWT_SECRET' => str_repeat('k', 64),
            'APP_CORS_ORIGIN' => '*',
        ];

        $config = AppConfig::fromEnvironment($env);

        self::assertSame(['*'], $config->corsOrigins);
    }

    /**
     * @dataProvider malformedCorsOriginProvider
     */
    public function testMalformedCorsOriginIsRejected(?string $origin): void
    {
        $env = $this->validProduction();
        $env['APP_CORS_ORIGIN'] = $origin;

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('is not a valid origin', implode(' ', $e->errors()));
        }
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function malformedCorsOriginProvider(): iterable
    {
        yield 'trailing slash' => ['https://zawody.pl/'];
        yield 'with path' => ['https://zawody.pl/app'];
        yield 'no scheme' => ['zawody.pl'];
        yield 'null byte-ish' => ['null'];
    }

    public function testProductionRequiresHttpsOrigins(): void
    {
        $env = $this->validProduction();
        $env['APP_CORS_ORIGIN'] = 'http://zawody.pl';

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('must use https', implode(' ', $e->errors()));
        }
    }

    public function testCorsListIsTrimmedAndEmptiesDropped(): void
    {
        $env = $this->validProduction();
        $env['APP_CORS_ORIGIN'] = ' https://a.pl , ,https://b.pl ';

        $config = AppConfig::fromEnvironment($env);

        self::assertSame(['https://a.pl', 'https://b.pl'], $config->corsOrigins);
    }

    public function testInvalidBooleanIsRejected(): void
    {
        $env = $this->validProduction();
        $env['APP_DEBUG'] = 'maybe';

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('must be a boolean', implode(' ', $e->errors()));
        }
    }

    public function testInvalidJwtTtlIsRejected(): void
    {
        foreach (['abc', '-5'] as $ttl) {
            $env = $this->validProduction();
            $env['JWT_TTL_SECONDS'] = $ttl;

            try {
                AppConfig::fromEnvironment($env);
                self::fail('Expected ConfigurationException.');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('JWT_TTL_SECONDS must be a positive integer', implode(' ', $e->errors()));
            }
        }
    }

    public function testUnsetJwtTtlDefaultsTo86400(): void
    {
        $env = $this->validProduction();
        unset($env['JWT_TTL_SECONDS']);

        $config = AppConfig::fromEnvironment($env);

        self::assertSame(86400, $config->jwtTtlSeconds);
    }

    public function testAllViolationsAreReportedAtOnce(): void
    {
        $env = [
            'APP_ENV' => AppConfig::ENV_PRODUCTION,
            'APP_DEBUG' => 'true',
            'APP_CORS_ORIGIN' => '*',
        ];

        try {
            AppConfig::fromEnvironment($env);
            self::fail('Expected ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertGreaterThanOrEqual(3, count($e->errors()));
        }
    }
}
