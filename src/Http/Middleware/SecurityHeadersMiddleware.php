<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** JSON/API responses never render as documents; lock everything down. */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const CONTENT_SECURITY_POLICY =
        "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';
    public const HSTS = 'max-age=31536000';

    public function __construct(private readonly bool $sendHsts)
    {
    }

    /**
     * @return array<string, string> header name => value (used by the middleware AND by index.php's
     *                                boot-failure branch)
     */
    public static function baselineHeaders(bool $sendHsts): array
    {
        $headers = [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
        ];
        if ($sendHsts) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        return $headers;
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);
        foreach (self::baselineHeaders($this->sendHsts) as $name => $value) {
            if (!$response->hasHeader($name)) {
                // A route may deliberately override (none does today).
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
