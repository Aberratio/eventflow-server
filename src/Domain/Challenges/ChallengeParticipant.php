<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

/**
 * Who takes part in a challenge: the account (`userId`) and, when known, the competitor profile
 * (`competitorId`) the progress belongs to — the account's own profile or a ward under its care.
 * A null competitor is an account without a profile, or a legacy row that could not be mapped.
 */
final class ChallengeParticipant
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $competitorId,
    ) {
    }

    /** @param array<string, mixed> $row a row with `userId` and a nullable `competitorId` */
    public static function fromRow(array $row): self
    {
        return new self((string) $row['userId'], self::nullableId($row['competitorId'] ?? null));
    }

    public static function nullableId(mixed $id): ?string
    {
        return $id !== null ? (string) $id : null;
    }
}
