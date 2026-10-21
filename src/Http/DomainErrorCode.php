<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http;

/**
 * The only way a caught RuntimeException's message may become a response `error.code`.
 * Domain codes in this codebase are snake_case identifiers thrown on purpose
 * (`throw new RuntimeException('file_too_large')`). Anything else (a PDOException with SQLSTATE
 * text, query fragments or duplicate-entry values; a library message; a message with a
 * colon/space/suffix such as `central_mailer_enqueue_failed: HTTP 500 ...`) is not a domain
 * code and is rethrown, so JsonErrorMiddleware turns it into a generic 500 AND logs it.
 */
final class DomainErrorCode
{
    public const PATTERN = '/^[a-z][a-z0-9_]{0,79}$/';

    /** @throws \RuntimeException the original exception, unchanged, when it is not a domain code */
    public static function of(\RuntimeException $e): string
    {
        $message = $e->getMessage();
        if ($e instanceof \PDOException || preg_match(self::PATTERN, $message) !== 1) {
            throw $e;
        }

        return $message;
    }
}
