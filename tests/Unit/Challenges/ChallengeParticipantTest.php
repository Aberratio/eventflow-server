<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Challenges;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Challenges\ChallengeParticipant;

final class ChallengeParticipantTest extends TestCase
{
    public function testFromRowCastsIdsToStrings(): void
    {
        $participant = ChallengeParticipant::fromRow(['userId' => 12, 'competitorId' => 34]);

        self::assertSame('12', $participant->userId);
        self::assertSame('34', $participant->competitorId);
    }

    public function testFromRowKeepsMissingCompetitorAsNull(): void
    {
        self::assertNull(ChallengeParticipant::fromRow(['userId' => 'u1', 'competitorId' => null])->competitorId);
        self::assertNull(ChallengeParticipant::fromRow(['userId' => 'u1'])->competitorId);
    }

    public function testNullableIdKeepsAnEmptyStringAsAnId(): void
    {
        self::assertSame('', ChallengeParticipant::nullableId(''));
        self::assertNull(ChallengeParticipant::nullableId(null));
    }
}
