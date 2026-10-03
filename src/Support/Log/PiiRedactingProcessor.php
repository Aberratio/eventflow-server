<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** PP.20: applies {@see PiiRedactor} to message, context and extra of every record. */
final class PiiRedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: PiiRedactor::scrubString($record->message),
            context: PiiRedactor::redactContext($record->context),
            extra: PiiRedactor::redactContext($record->extra),
        );
    }
}
