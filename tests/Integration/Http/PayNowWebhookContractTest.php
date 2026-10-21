<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * The webhook route itself, wired through the real routes.php (a real
 * PayNowNotificationVerifier underneath it — the same wiring production uses).
 * This repo's own test env has no PAYNOW_SIGNATURE_KEY set, so a genuinely
 * valid signature can't be produced against the real wiring here — that
 * accepting path is covered instead at the unit level
 * (tests/Unit/Payments/PayNowNotificationVerifierTest.php, against a real,
 * locally-known signature key), and the apply-notification business logic at
 * the repository level
 * (tests/Integration/RegistrationRepositoryGatewayProviderTest.php /
 * RegistrationRepositoryTpayTest.php). This test only exercises the paths
 * that must reject before trusting anything in the body.
 */
final class PayNowWebhookContractTest extends HttpTestCase
{
    public function testMissingSignatureHeaderIsRejected(): void
    {
        $response = $this->postRaw('/api/v1/webhooks/paynow', '{"paymentId":"PN-1","status":"CONFIRMED"}');

        self::assertSame(400, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['success']);
        self::assertSame('invalid_signature', $body['error']['code']);
    }

    public function testWrongSignatureIsRejectedWithoutApplyingAnything(): void
    {
        $response = $this->postRaw(
            '/api/v1/webhooks/paynow',
            '{"paymentId":"PN-1","status":"CONFIRMED"}',
            ['Signature' => base64_encode('not-the-real-signature')]
        );

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
