<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Gives every series enough events to look like a series.
 *
 * The seeders create seven of them but only eleven links between series and events, so
 * most series pages showed one race or none at all — a standings table with a single
 * column, and nothing to demonstrate what a cup or a championship actually is. Events are
 * assigned here rather than in the fixtures because which events exist is itself decided
 * at seed time.
 *
 * Assignment is by discipline where possible, so a running cup fills with running races,
 * and spans past and upcoming rounds so a series has both results behind it and rounds
 * still to come. Existing links are never disturbed.
 */
final class SeriesLinker
{
    /** How many events a series should end up with. */
    private const TARGET_PER_SERIES = 4;

    /**
     * @return array<string, int>
     */
    public static function apply(PDO $pdo): array
    {
        $series = $pdo->query(
            'SELECT s.id, s.name, s.type,
                    (SELECT COUNT(*) FROM series_events se WHERE se.series_id = s.id) AS linked
             FROM series s ORDER BY linked ASC, s.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($series === []) {
            return [];
        }

        // Only events nobody has claimed, and only ones the public can actually see.
        $available = $pdo->query(
            "SELECT id, discipline, date FROM events
             WHERE is_private = 0
               AND publication_status IN ('published', 'finished', 'cancelled')
               AND series_id IS NULL
               AND id NOT IN (SELECT event_id FROM series_events)
             ORDER BY date ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $link = $pdo->prepare(
            'INSERT INTO series_events (series_id, event_id, sort_order) VALUES (:seriesId, :eventId, :sortOrder)'
        );
        $claim = $pdo->prepare('UPDATE events SET series_id = :seriesId WHERE id = :eventId');

        $disciplineOf = self::dominantDisciplines($pdo);
        $used = [];
        $linked = 0;

        foreach ($series as $entry) {
            $need = self::TARGET_PER_SERIES - (int) $entry['linked'];
            if ($need <= 0) {
                continue;
            }

            $preferred = $disciplineOf[(string) $entry['id']] ?? null;
            $picked = self::pick($available, $used, $preferred, $need);

            $sortOrder = (int) $entry['linked'];
            foreach ($picked as $event) {
                $used[$event['id']] = true;
                $link->execute([
                    'seriesId' => $entry['id'],
                    'eventId' => $event['id'],
                    'sortOrder' => $sortOrder++,
                ]);
                $claim->execute(['seriesId' => $entry['id'], 'eventId' => $event['id']]);
                $linked++;
            }
        }

        return $linked > 0 ? ['series_events' => $linked] : [];
    }

    /**
     * The discipline a series already leans towards, so new rounds match the old ones.
     *
     * @return array<string, string>
     */
    private static function dominantDisciplines(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT se.series_id, e.discipline, COUNT(*) AS n
             FROM series_events se JOIN events e ON e.id = se.event_id
             GROUP BY se.series_id, e.discipline ORDER BY n DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $best = [];
        foreach ($rows as $row) {
            $best[(string) $row['series_id']] ??= (string) $row['discipline'];
        }

        return $best;
    }

    /**
     * Takes up to $need unused events, preferring the series' own discipline but falling
     * back to anything rather than leaving a series short. Picks are spread across the
     * list so a series gets both finished rounds and upcoming ones.
     *
     * @param array<int, array<string, mixed>> $available
     * @param array<string, true> $used
     * @return array<int, array<string, mixed>>
     */
    private static function pick(array $available, array $used, ?string $preferred, int $need): array
    {
        $matching = [];
        $rest = [];
        foreach ($available as $event) {
            if (isset($used[(string) $event['id']])) {
                continue;
            }
            if ($preferred !== null && (string) $event['discipline'] === $preferred) {
                $matching[] = $event;
            } else {
                $rest[] = $event;
            }
        }

        $pool = $matching === [] ? $rest : $matching;

        // Widen to any discipline rather than leave a series without a next round.
        $hasUpcoming = static function (array $events): bool {
            foreach ($events as $event) {
                if ((string) $event['date'] >= date('Y-m-d')) {
                    return true;
                }
            }
            return false;
        };
        if ($pool !== [] && !$hasUpcoming($pool) && $hasUpcoming($rest)) {
            $pool = array_merge($pool, $rest);
        }
        if ($pool === []) {
            return [];
        }

        // Take from finished and upcoming rounds alternately, so a series always shows both
        // a standings table and a date still to come.
        $today = date('Y-m-d');
        $past = $upcoming = [];
        foreach ($pool as $event) {
            if ((string) $event['date'] < $today) {
                $past[] = $event;
            } else {
                $upcoming[] = $event;
            }
        }

        // Walk each list at an even stride rather than taking consecutive weekends.
        $take = static function (array $list, int $count): array {
            if ($list === [] || $count <= 0) {
                return [];
            }
            $stride = max(1, intdiv(count($list), $count));
            $out = [];
            for ($i = 0; $i < count($list) && count($out) < $count; $i += $stride) {
                $out[] = $list[$i];
            }
            return $out;
        };

        $wantUpcoming = min(count($upcoming), max(1, intdiv($need, 2)));
        $picked = $take($upcoming, $wantUpcoming);
        $picked = array_merge($picked, $take($past, $need - count($picked)));

        // If one side ran dry, fill the remainder from the other.
        if (count($picked) < $need) {
            foreach (array_merge($upcoming, $past) as $event) {
                if (count($picked) >= $need) {
                    break;
                }
                if (!in_array($event, $picked, true)) {
                    $picked[] = $event;
                }
            }
        }

        usort($picked, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        return $picked;
    }
}
