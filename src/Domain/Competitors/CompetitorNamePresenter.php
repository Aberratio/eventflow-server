<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Competitors;

/**
 * E6.6 (issue 15 §6, "Ranking: publiczny, z respektowaniem anonimizacji"):
 * the one, reusable place that decides whether a competitor's real name may
 * be shown publicly. "tym samym mechanizmem co na liście startowej, nie
 * osobnym wyjątkiem" (spec) — a single static helper, not a per-feature
 * ad-hoc check, so every public read this ticket touches (and any future one
 * that wires the same `competitors.is_anonymized` flag in) shares the exact
 * same masking decision and format.
 *
 * Masked-name format: a judgment call, spec deliberately leaves it open
 * ("spec nie precyzuje dokladnego formatu, brak precedensu w kodzie").
 * Chosen: a fixed, fully generic label ("Zawodnik anonimowy") rather than
 * initials (e.g. "J.K.") — initials are still partially re-identifying when
 * cross-referenced against a public start list or bib number, which would
 * undermine the whole point of the flag; a flat label carries zero
 * information beyond "this competitor chose to be anonymous", matching the
 * binary "Nie / Pokaz" nature of the FormFieldCatalog toggle this mirrors.
 */
final class CompetitorNamePresenter
{
    public const ANONYMIZED_LABEL = 'Zawodnik anonimowy';

    /**
     * @param string $fullName the competitor's real display name (already
     *   assembled by the caller, e.g. "firstName lastName" or a cached
     *   `user_name`) — this helper never touches storage, it only decides
     *   whether to show or replace what it's given.
     */
    public static function publicName(string $fullName, bool $isAnonymized): string
    {
        return $isAnonymized ? self::ANONYMIZED_LABEL : $fullName;
    }
}
