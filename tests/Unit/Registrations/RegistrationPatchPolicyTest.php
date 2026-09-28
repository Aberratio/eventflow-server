<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Registrations;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Registrations\RegistrationPatchPolicy;

final class RegistrationPatchPolicyTest extends TestCase
{
    public function testOwnerMayOnlyChangeTeamName(): void
    {
        $result = RegistrationPatchPolicy::filter(['teamName' => 'X', 'bibNumber' => '1'], false);

        self::assertSame(['teamName' => 'X'], $result['allowed']);
        self::assertSame(['bibNumber'], $result['rejected']);
    }

    /** @dataProvider managerFlags */
    public function testStatusIsRejectedForOwnerAndManager(bool $isManager): void
    {
        $result = RegistrationPatchPolicy::filter(['status' => 'confirmed'], $isManager);

        self::assertSame([], $result['allowed']);
        self::assertSame(['status'], $result['rejected']);
    }

    public static function managerFlags(): array
    {
        return [[false], [true]];
    }

    public function testManagerWhitelist(): void
    {
        $result = RegistrationPatchPolicy::filter([
            'bibNumber' => '101',
            'teamName' => 'X',
            'checkedInAt' => '2026-01-01T00:00:00Z',
            'checkedInBy' => 'u-1',
            'discountCode' => 'ABC',
            'removedAt' => '2026-01-01T00:00:00Z',
            'teamId' => 't-1',
            'paymentDetails' => ['startFeePaid' => true],
            'merchandise' => [],
            'merchandiseOrders' => [],
        ], true);

        self::assertSame([
            'bibNumber' => '101',
            'teamName' => 'X',
            'checkedInAt' => '2026-01-01T00:00:00Z',
            'checkedInBy' => 'u-1',
        ], $result['allowed']);
        self::assertSame(
            ['discountCode', 'removedAt', 'teamId', 'paymentDetails', 'merchandise', 'merchandiseOrders'],
            $result['rejected']
        );
    }
}
