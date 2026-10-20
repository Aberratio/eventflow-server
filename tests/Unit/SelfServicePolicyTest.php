<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Registrations\SelfServicePolicy;

final class SelfServicePolicyTest extends TestCase
{
    private const NOW = '2026-06-01 12:00:00';

    private function policy(): SelfServicePolicy
    {
        return new SelfServicePolicy(static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::NOW));
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-06-20',
            'publicationStatus' => 'published',
            'selfServiceEnabled' => true,
            'selfServiceDeadline' => null,
        ], $overrides);
    }

    public function testOwnerMayActInsideTheDefaultWindow(): void
    {
        $verdict = $this->policy()->evaluate($this->event(), ['status' => 'confirmed'], false);

        self::assertTrue($verdict['allowed']);
        self::assertNull($verdict['reason']);
        self::assertSame('2026-06-13', substr((string) $verdict['deadline'], 0, 10));
    }

    public function testDefaultWindowClosesSevenDaysBeforeTheStart(): void
    {
        $event = $this->event(['date' => '2026-06-08']);

        // 2026-06-01 12:00 is after the default cut-off 2026-06-01 00:00.
        $verdict = $this->policy()->evaluate($event, ['status' => 'pending'], false);

        self::assertFalse($verdict['allowed']);
        self::assertSame(SelfServicePolicy::REASON_CLOSED, $verdict['reason']);
    }

    public function testCustomDeadlineBoundaryIsExclusive(): void
    {
        $open = $this->event(['selfServiceDeadline' => '2026-06-01 12:00:01']);
        $closed = $this->event(['selfServiceDeadline' => '2026-06-01 12:00:00']);

        self::assertTrue($this->policy()->evaluate($open, ['status' => 'confirmed'], false)['allowed']);
        self::assertSame(
            SelfServicePolicy::REASON_CLOSED,
            $this->policy()->evaluate($closed, ['status' => 'confirmed'], false)['reason']
        );
    }

    public function testSwitchedOffEventRefusesTheOwner(): void
    {
        $verdict = $this->policy()->evaluate($this->event(['selfServiceEnabled' => false]), ['status' => 'confirmed'], false);

        self::assertFalse($verdict['allowed']);
        self::assertSame(SelfServicePolicy::REASON_DISABLED, $verdict['reason']);
    }

    public function testManagerIsNeverRestricted(): void
    {
        $event = $this->event(['selfServiceEnabled' => false, 'date' => '2026-05-01', 'publicationStatus' => 'finished']);

        self::assertTrue($this->policy()->evaluate($event, ['status' => 'cancelled'], true)['allowed']);
    }

    public function testInactiveRegistrationIsRefused(): void
    {
        foreach (['cancelled', 'removed', 'expired'] as $status) {
            $verdict = $this->policy()->evaluate($this->event(), ['status' => $status], false);
            self::assertSame(SelfServicePolicy::REASON_REGISTRATION_INACTIVE, $verdict['reason'], $status);
        }
    }

    public function testCancelledOrStartedEventIsOver(): void
    {
        $cancelled = $this->policy()->evaluate($this->event(['publicationStatus' => 'cancelled']), ['status' => 'confirmed'], false);
        $started = $this->policy()->evaluate($this->event(['date' => '2026-06-01 08:00:00', 'selfServiceDeadline' => '2026-06-01 20:00:00']), ['status' => 'confirmed'], false);

        self::assertSame(SelfServicePolicy::REASON_EVENT_OVER, $cancelled['reason']);
        self::assertSame(SelfServicePolicy::REASON_EVENT_OVER, $started['reason']);
    }

    public function testDeadlineValidation(): void
    {
        $event = $this->event();

        self::assertTrue(SelfServicePolicy::deadlineIsValid(null, $event));
        self::assertTrue(SelfServicePolicy::deadlineIsValid('2026-06-19 23:00', $event));
        self::assertFalse(SelfServicePolicy::deadlineIsValid('2026-06-20', $event), 'not before the start');
        self::assertFalse(SelfServicePolicy::deadlineIsValid('2026-06-25', $event));
        self::assertFalse(SelfServicePolicy::deadlineIsValid('not a date', $event));
    }

    public function testNormalizeDeadline(): void
    {
        self::assertSame('2026-06-10 18:30:00', SelfServicePolicy::normalizeDeadline('2026-06-10T18:30'));
        self::assertNull(SelfServicePolicy::normalizeDeadline(''));
        self::assertNull(SelfServicePolicy::normalizeDeadline(null));
    }
}
