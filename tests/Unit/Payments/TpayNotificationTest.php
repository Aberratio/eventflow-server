<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Payments\Tpay\TpayNotification;

final class TpayNotificationTest extends TestCase
{
    public function testParsesTransactionsApiJsonNotificationAsPaid(): void
    {
        $body = json_encode([
            'transactionId' => 'TX-1',
            'transactionStatus' => 'correct',
            'transactionAmount' => 99.99,
            'transactionHiddenDescription' => 'reg-1',
        ]);

        $notification = TpayNotification::fromRawBody((string) $body, 'application/json');

        self::assertSame('TX-1', $notification->transactionId);
        self::assertTrue($notification->paid);
        self::assertSame(99.99, $notification->amount);
        self::assertSame('reg-1', $notification->registrationId);
    }

    public function testParsesClassicFormEncodedNotificationAsPaid(): void
    {
        $body = http_build_query([
            'tr_id' => 'TX-2',
            'tr_status' => 'TRUE',
            'tr_amount' => '55.00',
            'tr_crc' => 'reg-2',
        ]);

        $notification = TpayNotification::fromRawBody($body, 'application/x-www-form-urlencoded');

        self::assertSame('TX-2', $notification->transactionId);
        self::assertTrue($notification->paid);
        self::assertSame(55.0, $notification->amount);
        self::assertSame('reg-2', $notification->registrationId);
    }

    public function testNonSuccessStatusIsNotPaid(): void
    {
        $body = json_encode([
            'transactionId' => 'TX-3',
            'transactionStatus' => 'chargeback',
            'transactionAmount' => 10,
        ]);

        $notification = TpayNotification::fromRawBody((string) $body, 'application/json');

        self::assertFalse($notification->paid);
    }

    public function testMissingTransactionIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        TpayNotification::fromRawBody((string) json_encode(['transactionStatus' => 'correct']), 'application/json');
    }
}
