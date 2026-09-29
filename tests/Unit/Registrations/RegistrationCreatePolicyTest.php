<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Registrations;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Registrations\RegistrationCreatePolicy;

final class RegistrationCreatePolicyTest extends TestCase
{
    public function testKeepsEverySelectionField(): void
    {
        $payload = [];
        foreach (RegistrationCreatePolicy::SELECTION_FIELDS as $i => $field) {
            $payload[$field] = 'value-' . $i;
        }

        $result = RegistrationCreatePolicy::filter($payload);

        self::assertSame($payload, $result['allowed']);
    }

    public function testDropsIdentityDisplayAndUnknownFields(): void
    {
        $result = RegistrationCreatePolicy::filter([
            'eventId' => 'e1',
            'userId' => 'u1',
            'userName' => 'X',
            'userEmail' => 'x@example.test',
            'registeredById' => 'u2',
            'eventName' => 'E',
            'distanceName' => 'D',
            'birthDate' => '2000-01-01',
            'paymentDetails' => ['startFeePaid' => false],
            'status' => 'confirmed',
            'foo' => 'bar',
        ]);

        self::assertSame(['eventId' => 'e1'], $result['allowed']);
    }

    public function testClaimedUserIdIsExposedOnlyWhenPresent(): void
    {
        self::assertSame('u1', RegistrationCreatePolicy::filter(['userId' => 'u1'])['claimedUserId']);
        self::assertNull(RegistrationCreatePolicy::filter([])['claimedUserId']);
        self::assertNull(RegistrationCreatePolicy::filter(['userId' => ''])['claimedUserId']);
    }

    /**
     * @dataProvider paidClaims
     */
    public function testPaidClaimsAreDetected(mixed $paymentDetails): void
    {
        self::assertTrue(RegistrationCreatePolicy::filter(['paymentDetails' => $paymentDetails])['paidClaim']);
        self::assertTrue(RegistrationCreatePolicy::claimsPayment($paymentDetails));
    }

    public static function paidClaims(): array
    {
        return [
            'startFeePaid true' => [['startFeePaid' => true]],
            'insurancePaid true' => [['insurancePaid' => true]],
            'startFeePaid int' => [['startFeePaid' => 1]],
            'startFeePaid string' => [['startFeePaid' => 'true']],
            'merchandise paid' => [['merchandisePayments' => [['merchandiseId' => 'm', 'paid' => true]]]],
            'scalar true' => [true],
        ];
    }

    public function testUnpaidOrAbsentPaymentDetailsIsNotAClaim(): void
    {
        self::assertFalse(RegistrationCreatePolicy::filter([])['paidClaim']);
        self::assertFalse(RegistrationCreatePolicy::filter(['paymentDetails' => null])['paidClaim']);
        foreach ([
            [],
            ['startFeePaid' => false, 'insurancePaid' => false],
            ['startFeePaidAt' => '2026-01-01T00:00:00+00:00'],
            ['merchandisePayments' => [['paid' => false]]],
        ] as $details) {
            self::assertFalse(RegistrationCreatePolicy::filter(['paymentDetails' => $details])['paidClaim']);
        }
    }
}
