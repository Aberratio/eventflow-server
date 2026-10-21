<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

/**
 * Anonymization for public rankings:
 * the one, reusable place that decides whether a competitor's real name may
 * be shown publicly. Same mechanism as the start list, not
 * a separate exception — a single static helper, not a per-feature
 * ad-hoc check, so every public read (and any future one
 * that wires the same `competitors.is_anonymized` flag in) shares the exact
 * same masking decision and format.
 *
 * Masked-name format: a judgment call, deliberately left open
 * (the requirements give no exact format and the code has no precedent).
 * Chosen: the label "Anonim #<kod>" (stable per Zawodnik, see anonymousLabel()) rather than
 * initials (e.g. "J.K.") — initials are still partially re-identifying when
 * cross-referenced against a public start list or bib number, which would
 * undermine the whole point of the flag; a flat label carries zero
 * information beyond "this competitor chose to be anonymous", matching the
 * binary "Nie / Pokaz" nature of the FormFieldCatalog toggle this mirrors.
 */
final class CompetitorNamePresenter
{
    /** Fallback when no competitor id is known (normally the label is Anonim #<kod>). */
    public const ANONYMIZED_LABEL = 'Anonim';

    public const LABEL_PREFIX = 'Anonim #';

    /**
     * @param string $fullName the competitor's real display name (already
     *   assembled by the caller, e.g. "firstName lastName" or a cached
     *   `user_name`) — this helper never touches storage, it only decides
     *   whether to show or replace what it's given.
     */
    public static function publicName(string $fullName, bool $isAnonymized, ?string $competitorId = null): string
    {
        return $isAnonymized ? self::anonymousLabel($competitorId) : $fullName;
    }

    /**
     * `Anonim #<kod>`. The code is stable per Zawodnik (the same across Events), derived
     * from the competitor id with a one-way hash - never the secret QR identity code - and carries no
     * personal data. Without an id the plain fallback label is returned.
     */
    public static function anonymousLabel(?string $competitorId): string
    {
        if ($competitorId === null || $competitorId === '') {
            return self::ANONYMIZED_LABEL;
        }

        return self::LABEL_PREFIX . strtoupper(substr(hash('sha256', 'anonim:' . $competitorId), 0, 8));
    }
}
