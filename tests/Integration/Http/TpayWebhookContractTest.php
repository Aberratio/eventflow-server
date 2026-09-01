<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E1.6: the webhook route itself, wired through the real routes.php (real
 * TpayNotificationVerifier + a real CurlHttpClient underneath it — the same
 * wiring production uses). This deliberately only exercises the paths that can
 * be rejected *before* any certificate would need fetching (missing signature
 * header, and an x5u host that isn't Tpay's) — per this ticket's "never hit a
 * real external host in tests" constraint, the *accepting* path (a genuinely
 * valid signature) is covered instead at the unit level
 * (tests/Unit/Payments/TpayNotificationVerifierTest.php) against a fake HTTP
 * client and a real, locally generated certificate chain, and the
 * apply-notification business logic is covered at the repository level
 * (tests/Integration/RegistrationRepositoryTpayTest.php).
 */
final class TpayWebhookContractTest extends HttpTestCase
{
    public function testMissingSignatureHeaderIsRejectedWithoutAnyNetworkCall(): void
    {
        $response = $this->postRaw('/api/v1/webhooks/tpay', '{"transactionId":"TX-1","transactionStatus":"correct"}');

        self::assertSame(400, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['success']);
        self::assertSame('invalid_signature', $body['error']['code']);
    }

    public function testSignatureFromAnUntrustedHostIsRejectedWithoutFetchingIt(): void
    {
        $header = rtrim(strtr(base64_encode((string) json_encode(['alg' => 'RS256', 'x5u' => 'https://attacker.example.com/cert.pem'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('{"transactionId":"TX-1"}'), '+/', '-_'), '=');
        $jws = $header . '.' . $payload . '.' . 'fake-signature';

        $response = $this->postRaw('/api/v1/webhooks/tpay', '{"transactionId":"TX-1"}', ['X-JWS-Signature' => $jws]);

        self::assertSame(400, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('invalid_signature', $body['error']['code']);
    }

    /** @param array<string, string> $headers */
    private function postRaw(string $path, string $rawBody, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path);
        $request = $request->withBody((new StreamFactory())->createStream($rawBody));
        $request = $request->withHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }
}
