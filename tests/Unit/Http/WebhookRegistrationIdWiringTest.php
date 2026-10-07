<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PP.63 wiring guard. No valid Tpay JWS signature can reach the live route in tests (the
 * certificate is fetched from the x5u URL), so the Tpay route is covered here as a contract on
 * routes.php: both webhook closures must hand the parsed notification's registration id to
 * applyGatewayNotification(). The PayNow route is additionally driven end to end in
 * PayNowWebhookIdentityContractTest.
 */
final class WebhookRegistrationIdWiringTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function webhooks(): array
    {
        return [
            'tpay' => ["/webhooks/tpay'", "/webhooks/paynow'"],
            'paynow' => ["/webhooks/paynow'", "/webhooks/tpay'"],
        ];
    }

    #[DataProvider('webhooks')]
    public function testApplyGatewayNotificationReceivesTheNotifiedRegistrationId(string $startMarker, string $otherMarker): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Http/routes.php');
        $start = strpos($source, $startMarker);
        self::assertNotFalse($start, 'webhook route not found');
        $end = strpos($source, "\$api->get('/virtual-results'", $start);
        $next = strpos($source, $otherMarker, $start);
        $limit = $next !== false && $next > $start ? $next : $end;
        self::assertNotFalse($limit);
        $closure = substr($source, $start, $limit - $start);

        self::assertSame(1, preg_match('/applyGatewayNotification\((.*?)\);/s', $closure, $call), 'call not found');
        self::assertStringContainsString('$notification->registrationId', $call[1]);
    }
}
