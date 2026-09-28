<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Config;

use RuntimeException;

/**
 * Thrown by {@see AppConfig::fromEnvironment()} when the environment is invalid.
 *
 * Carries every violation found, not just the first, so the caller (a boot
 * guard, or `bin/check-config.php`) can report the whole list at once instead
 * of forcing a fix-one-restart-fix-another loop.
 */
final class ConfigurationException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid configuration: ' . implode(' ', $errors));
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
