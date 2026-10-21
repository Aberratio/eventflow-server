<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Competitors\CompetitorNamePresenter;

/**
 * The single shared masking decision every public read
 * that respects `competitors.is_anonymized` goes through.
 */
final class CompetitorNamePresenterTest extends TestCase
{
    public function testNonAnonymizedCompetitorShowsRealName(): void
    {
        self::assertSame('Jan Kowalski', CompetitorNamePresenter::publicName('Jan Kowalski', false));
    }

    public function testAnonymizedLabelIsAnonimHashCodeStablePerCompetitor(): void
    {
        $label = CompetitorNamePresenter::publicName('Jan Kowalski', true, '0b6e6d1c-5f0a-4c07-9f3e-2f4c8d6a1b11');

        self::assertMatchesRegularExpression('/^Anonim #[0-9A-F]{8}$/', $label);
        self::assertSame($label, CompetitorNamePresenter::anonymousLabel('0b6e6d1c-5f0a-4c07-9f3e-2f4c8d6a1b11'), 'stable across calls (and Events)');
        self::assertNotSame($label, CompetitorNamePresenter::anonymousLabel('another-competitor-id'));
        self::assertStringNotContainsString('Kowalski', $label);
    }

    public function testAnonymizedCompetitorNeverShowsTheRealNameAnywhereInOutput(): void
    {
        $masked = CompetitorNamePresenter::publicName('Jan Kowalski', true);

        self::assertSame(CompetitorNamePresenter::ANONYMIZED_LABEL, $masked, 'no id known: plain fallback');
        self::assertStringNotContainsString('Jan', $masked);
        self::assertStringNotContainsString('Kowalski', $masked);
    }
}
