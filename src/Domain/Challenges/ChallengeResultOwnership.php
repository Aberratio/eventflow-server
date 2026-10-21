<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Challenges;

/**
 * SQL fragments that attribute a `results` row to a Zawodnik,
 * the same rule ResultRepository uses for lifetime records:
 * `registrations.competitor_id` (via `results.registration_id`) is authoritative,
 * and the Zawodnik whose own Konto produced the result (`competitors.user_id =
 * results.user_id`) is the fallback for manual results with no registration.
 *
 * Callers alias the results table as `r` and append JOINS to its FROM clause.
 */
final class ChallengeResultOwnership
{
    public const JOINS = ' LEFT JOIN registrations reg ON reg.id = r.registration_id'
        . ' LEFT JOIN competitors comp_self ON comp_self.user_id = r.user_id';

    public const COMPETITOR_EXPR = 'COALESCE(reg.competitor_id, comp_self.id)';
}
