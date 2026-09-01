<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\PayNow\PayNowNotification;

final class PayNowNotificationTest extends TestCase
{
    public function testParsesAConfirmedNotificationAsPaid(): void
    {
        $body = json_encode([
            'paymentId' => 'PN-1',
            'externalId' => 'reg-1',
            'status' => 'CONFIRMED',
            'modifiedAt' => '2026-06-15T12:00:00',
        ]);

        $notification = PayNowNotification::fromRawBody((string) $body);

        self::assertSame('PN-1', $notification->transactionId);
        self::assertTrue($notification->paid);
        self::assertSame('reg-1', $notification->registrationId);
    }

    /** @dataProvider nonPaidStatuses */
    public function testNonConfirmedStatusIsNotPaid(string $status): void
    {
        $body = json_encode(['paymentId' => 'PN-2', 'status' => $status]);

        $notification = PayNowNotification::fromRawBody((string) $body);

        self::assertFalse($notification->paid);
    }

    /** @return array<int, array{0: string}> */
    public static function nonPaidStatuses(): array
    {
        return [['NEW'], ['PENDING'], ['REJECTED'], ['EXPIRED'], ['ABANDONED'], ['ERROR']];
    }

    public function testMissingPaymentIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        PayNowNotification::fromRawBody((string) json_encode(['status' => 'CONFIRMED']));
    }

    public function testMalformedBodyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        PayNowNotification::fromRawBody('not json');
    }

    public function testMissingExternalIdYieldsNullRegistrationId(): void
    {
        $notification = PayNowNotification::fromRawBody((string) json_encode(['paymentId' => 'PN-3', 'status' => 'CONFIRMED']));

        self::assertNull($notification->registrationId);
    }
}
