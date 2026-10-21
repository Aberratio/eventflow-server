<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Retention;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ZmierzymyCzas\Support\Env;

/**
 * Retention periods of the 'retention.purge' job, read from the environment.
 * A value that is not a positive integer falls back to the default and logs a warning.
 */
final class RetentionConfig
{
    public const ENV_CONFIDENTIAL_ANSWERS_DAYS = 'RETENTION_CONFIDENTIAL_ANSWERS_DAYS';
    public const ENV_ACTIVITY_LOG_MONTHS = 'RETENTION_ACTIVITY_LOG_MONTHS';
    public const DEFAULT_CONFIDENTIAL_ANSWERS_DAYS = 90;
    public const DEFAULT_ACTIVITY_LOG_MONTHS = 12;

    public function __construct(
        public readonly int $confidentialAnswersDays = self::DEFAULT_CONFIDENTIAL_ANSWERS_DAYS,
        public readonly int $activityLogMonths = self::DEFAULT_ACTIVITY_LOG_MONTHS,
    ) {
    }

    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        $logger ??= new NullLogger();

        return new self(
            self::positiveInt(self::ENV_CONFIDENTIAL_ANSWERS_DAYS, self::DEFAULT_CONFIDENTIAL_ANSWERS_DAYS, $logger),
            self::positiveInt(self::ENV_ACTIVITY_LOG_MONTHS, self::DEFAULT_ACTIVITY_LOG_MONTHS, $logger),
        );
    }

    private static function positiveInt(string $key, int $default, LoggerInterface $logger): int
    {
        $raw = Env::get($key);
        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }
        $value = trim((string) $raw);
        if (preg_match('/^[1-9][0-9]{0,5}$/', $value) !== 1) {
            $logger->warning('Invalid retention setting, using default.', ['key' => $key, 'value' => $value, 'default' => $default]);

            return $default;
        }

        return (int) $value;
    }
}
