<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\Http;

use ZmierzymyCzas\Domain\Errors\Internal;

/**
 * The one real implementation of HttpClientInterface used outside tests — a thin
 * curl wrapper, identical in shape to
 * ZmierzymyCzas\Domain\Payments\Http\CurlHttpClient. No PSR-18/Guzzle dependency
 * exists in this repo (composer.json), so this is intentionally minimal rather
 * than pulling in a new package for what boils down to a handful of outbound
 * calls (enqueue a message, poll a status).
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(private readonly int $timeoutSeconds = 15)
    {
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Internal('central_mailer_http_init_failed');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('central_mailer_http_request_failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return new HttpResponse($status, (string) $result, $responseHeaders);
    }
}
