<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Competitors\CompetitorNamePresenter;

/**
 * E6.6 (issue 15 §6): the single shared masking decision every public read
 * that respects `competitors.is_anonymized` goes through.
 */
final class CompetitorNamePresenterTest extends TestCase
{
    public function testNonAnonymizedCompetitorShowsRealName(): void
    {
        self::assertSame('Jan Kowalski', CompetitorNamePresenter::publicName('Jan Kowalski', false));
    }

    public function testAnonymizedCompetitorNeverShowsTheRealNameAnywhereInOutput(): void
    {
        $masked = CompetitorNamePresenter::publicName('Jan Kowalski', true);

        self::assertSame(CompetitorNamePresenter::ANONYMIZED_LABEL, $masked);
        self::assertStringNotContainsString('Jan', $masked);
        self::assertStringNotContainsString('Kowalski', $masked);
    }
}
