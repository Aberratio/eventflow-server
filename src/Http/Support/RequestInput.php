<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/** Typed access to the parts of a request every handler reads. */
final class RequestInput
{
    /** @return array<string, mixed> the parsed JSON/form body, or [] when there is none */
    public static function body(Request $request): array
    {
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }

    /** @return array<string, mixed> */
    public static function query(Request $request): array
    {
        return $request->getQueryParams();
    }

    /** @return array<string, mixed> the verified session payload set by AuthMiddleware ([] for guests) */
    public static function auth(Request $request): array
    {
        return (array) $request->getAttribute('auth');
    }
}
