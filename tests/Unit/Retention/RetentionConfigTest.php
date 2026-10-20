<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Retention;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ZmierzymyCzas\Domain\Retention\RetentionConfig;
use ZmierzymyCzas\Support\Env;

final class RetentionConfigTest extends TestCase
{
    private const KEYS = [RetentionConfig::ENV_CONFIDENTIAL_ANSWERS_DAYS, RetentionConfig::ENV_ACTIVITY_LOG_MONTHS];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            Env::restore($key);
        }
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $this->setEnv('', '');
        $config = RetentionConfig::fromEnv();

        self::assertSame(90, $config->confidentialAnswersDays);
        self::assertSame(12, $config->activityLogMonths);
    }

    public function testOverridesFromEnvironment(): void
    {
        $this->setEnv('30', '6');
        $config = RetentionConfig::fromEnv();

        self::assertSame(30, $config->confidentialAnswersDays);
        self::assertSame(6, $config->activityLogMonths);
    }

    /** @dataProvider invalidValues */
    public function testInvalidValueFallsBackToDefaultAndWarns(string $bad): void
    {
        $this->setEnv($bad, $bad);
        $logger = new class extends AbstractLogger {
            /** @var array<int, string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) ($context['key'] ?? '');
                }
            }
        };

        $config = RetentionConfig::fromEnv($logger);

        self::assertSame(90, $config->confidentialAnswersDays);
        self::assertSame(12, $config->activityLogMonths);
        self::assertSame(self::KEYS, $logger->warnings);
    }

    /** Env::get() treats "0" as unset (falsy `?:`), so zero reaches the config as the default, without a warning. */
    public function testZeroIsNeverAccepted(): void
    {
        $this->setEnv('0', '0');
        $config = RetentionConfig::fromEnv();

        self::assertSame(90, $config->confidentialAnswersDays);
        self::assertSame(12, $config->activityLogMonths);
    }

    /** @return array<string, array<int, string>> */
    public static function invalidValues(): array
    {
        return ['negative' => ['-5'], 'text' => ['abc'], 'decimal' => ['1.5'], 'padded zero' => ['00']];
    }

    private function setEnv(string $days, string $months): void
    {
        Env::set(RetentionConfig::ENV_CONFIDENTIAL_ANSWERS_DAYS, $days);
        Env::set(RetentionConfig::ENV_ACTIVITY_LOG_MONTHS, $months);
    }
}
