<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;

/** PP.20: the only place that knows the Monolog handler wiring. */
final class LoggerFactory
{
    public const CHANNEL = 'zc';

    private const LINE_FORMAT = "%channel%.%level_name%: %message% %context%\n";

    /** Never throws. */
    public static function create(LogConfig $config): LoggerInterface
    {
        try {
            $logger = new Logger(self::CHANNEL);
            // Processors run LIFO: the redactor is pushed last so it runs first.
            $logger->pushProcessor(new UidProcessor(16));
            $logger->pushProcessor(new PiiRedactingProcessor());

            $warnings = $config->warnings;
            $handlers = [];
            $dirWritable = false;

            $dir = $config->logDir;
            if ($dir !== null) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                $dirWritable = is_dir($dir) && is_writable($dir);
                if (!$dirWritable) {
                    $warnings[] = 'LOG_DIR not writable, falling back to error_log';
                }
            }

            if ($dir !== null && $dirWritable) {
                $file = new RotatingFileHandler($dir . '/app.log', $config->maxFiles, $config->level, true, 0600);
                $file->setFormatter(new JsonFormatter());
                $handlers[] = $file;
            } else {
                $handlers[] = self::errorLogHandler($config->level);
            }

            $logger->pushHandler(new WhatFailureGroupHandler($handlers));

            foreach ($warnings as $warning) {
                $logger->warning($warning);
            }

            return $logger;
        } catch (\Throwable) {
            return self::fallback();
        }
    }

    /** error_log()-backed logger with the same redaction; used whenever nothing was injected. */
    public static function fallback(): LoggerInterface
    {
        $logger = new Logger(self::CHANNEL);
        $logger->pushProcessor(new PiiRedactingProcessor());
        $logger->pushHandler(new WhatFailureGroupHandler([self::errorLogHandler(Level::Debug)]));

        return $logger;
    }

    private static function errorLogHandler(Level $level): HandlerInterface
    {
        $handler = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $level);
        $handler->setFormatter(new LineFormatter(self::LINE_FORMAT, null, false, true));

        return $handler;
    }
}
