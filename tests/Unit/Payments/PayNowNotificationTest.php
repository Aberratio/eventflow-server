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
    public function testNonConfirmedStatusIsNotPaid(string $status, bool $failed): void
    {
        $body = json_encode(['paymentId' => 'PN-2', 'status' => $status]);

        $notification = PayNowNotification::fromRawBody((string) $body);

        self::assertFalse($notification->paid);
        self::assertSame($failed, $notification->failed);
    }

    /** @return array<int, array{0: string, 1: bool}> status, whether it is a final failure */
    public static function nonPaidStatuses(): array
    {
        return [
            ['NEW', false], ['PENDING', false], ['SOMETHING_NEW', false], ['', false],
            ['REJECTED', true], ['EXPIRED', true], ['ABANDONED', true], ['ERROR', true], ['rejected', true],
        ];
    }

    public function testConfirmedIsNeverFailed(): void
    {
        $notification = PayNowNotification::fromRawBody((string) json_encode(['paymentId' => 'PN-4', 'status' => 'CONFIRMED']));

        self::assertTrue($notification->paid);
        self::assertFalse($notification->failed);
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
