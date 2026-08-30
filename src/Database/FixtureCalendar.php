<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use DateTimeImmutable;

/**
 * Re-anchors the fixture dates around the day of the seed run.
 *
 * The fixtures were authored with absolute dates, which rots in two ways. They go stale —
 * a set written in early 2026 is almost entirely in the past by the autumn, leaving the
 * calendar with nothing to show. And they contradict themselves: twelve events carry
 * status `active` while being dated before the newest `past` one, so the same event reads
 * as upcoming or finished depending on which field you trust.
 *
 * So the authored **status** is treated as the intent — this event is meant to be
 * finished, this one is meant to be open for registration — and the date is recomputed to
 * match, relative to today. Events keep their order within their group, so a set that told
 * a story still tells it. Every other date belonging to an event (price tiers, discount
 * codes, registrations, payments, result documents) shifts by that event's delta, keeping
 * each event internally consistent.
 *
 * The result: a seed run always produces a calendar with recent history and a full
 * upcoming season, whenever it is run.
 */
final class FixtureCalendar
{
    /** Statuses whose events are meant to have already happened. */
    private const FINISHED = ['past', 'archived'];

    /** Statuses that are not public yet but describe a future event. */
    private const UNPUBLISHED = ['draft', 'awaiting_approval', 'approved'];

    /**
     * The season runs from roughly a year before the seed date to a year after it. Seeded
     * on 30 August 2026 that is August 2025 through August 2027: a full year of history
     * with results to browse, and a full year of racing still to enter.
     */
    private const FINISHED_WINDOW = [390, 14];

    /** Published upcoming events land between these many days after today. */
    private const UPCOMING_WINDOW = [7, 365];

    /** Unpublished events sit further out, where a draft plausibly would. */
    private const UNPUBLISHED_WINDOW = [90, 330];

    private readonly DateTimeImmutable $today;

    public function __construct(?DateTimeImmutable $today = null)
    {
        $this->today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /**
     * @param array<string, mixed> $data the whole fixture payload
     * @return array<string, mixed>
     */
    public function reanchor(array $data): array
    {
        $events = $data['events'] ?? [];
        if ($events === []) {
            return $data;
        }

        $deltas = $this->deltasByEvent($events);

        $data['events'] = array_map(
            fn (array $event): array => $this->shiftEvent($event, $deltas[(string) $event['id']] ?? 0),
            $events
        );

        // Everything that belongs to an event moves with it.
        foreach (['registrations', 'results', 'resultDocuments', 'virtualResultSubmissions'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            $data[$key] = array_map(
                fn (array $row): array => $this->shiftDates($row, $deltas[(string) ($row['eventId'] ?? '')] ?? 0),
                $data[$key]
            );
        }

        $data['series'] = $this->rebuildSeriesSpans($data['series'] ?? [], $data['events']);

        return $data;
    }

    /**
     * How many days each event moves. Events are grouped by what their status says they
     * are, then spread evenly across that group's window in their original order.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, int>
     */
    private function deltasByEvent(array $events): array
    {
        $groups = ['finished' => [], 'upcoming' => [], 'unpublished' => [], 'live' => []];

        foreach ($events as $event) {
            $status = (string) ($event['status'] ?? 'active');
            $group = match (true) {
                !empty($event['isLive']) => 'live',
                in_array($status, self::FINISHED, true) => 'finished',
                in_array($status, self::UNPUBLISHED, true) => 'unpublished',
                default => 'upcoming',
            };
            $groups[$group][] = ['id' => (string) $event['id'], 'date' => (string) ($event['date'] ?? '')];
        }

        $deltas = [];

        foreach ($groups['live'] as $event) {
            $deltas[$event['id']] = $this->daysBetween($event['date'], $this->today->format('Y-m-d'));
        }

        $windows = [
            'finished' => [-self::FINISHED_WINDOW[0], -self::FINISHED_WINDOW[1]],
            'upcoming' => [self::UPCOMING_WINDOW[0], self::UPCOMING_WINDOW[1]],
            'unpublished' => [self::UNPUBLISHED_WINDOW[0], self::UNPUBLISHED_WINDOW[1]],
        ];

        foreach ($windows as $group => [$from, $to]) {
            $members = $groups[$group];
            if ($members === []) {
                continue;
            }

            // Keep the story: whatever order these events were in, they stay in it.
            usort($members, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

            $count = count($members);
            foreach (array_values($members) as $index => $event) {
                $offset = $count === 1
                    ? (int) round(($from + $to) / 2)
                    : (int) round($from + ($to - $from) * ($index / ($count - 1)));

                $target = $this->today->modify(($offset >= 0 ? '+' : '-') . abs($offset) . ' days');
                $deltas[$event['id']] = $this->daysBetween($event['date'], $target->format('Y-m-d'));
            }
        }

        return $deltas;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function shiftEvent(array $event, int $delta): array
    {
        $originalYear = substr((string) ($event['date'] ?? ''), 0, 4);
        $event = $this->shiftDates($event, $delta);
        $event = $this->retitleForYear($event, $originalYear);

        // A few fixtures close registration after the event has already run.
        $date = (string) ($event['date'] ?? '');
        $deadline = (string) ($event['registrationDeadline'] ?? '');
        if ($date !== '' && $deadline !== '' && $deadline > $date) {
            $event['registrationDeadline'] = $date;
        }

        return $event;
    }

    /**
     * Most of these events carry their year in the name — "Maraton Warszawski 2025". Once
     * the date has moved, that reads as a mistake, so the year in the name and the slug is
     * moved with it. Only the event's own original year is replaced, leaving other numbers
     * (a distance, an edition number) untouched.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function retitleForYear(array $event, string $originalYear): array
    {
        $newYear = substr((string) ($event['date'] ?? ''), 0, 4);
        if ($originalYear === '' || $newYear === '' || $originalYear === $newYear) {
            return $event;
        }

        foreach (['name', 'slug'] as $field) {
            if (isset($event[$field]) && is_string($event[$field])) {
                $event[$field] = str_replace($originalYear, $newYear, $event[$field]);
            }
        }

        return $event;
    }

    /**
     * Walks a structure and moves every value that is a plain date or an ISO timestamp.
     * Recursing rather than listing fields means nested price tiers, discount-code windows
     * and payment timestamps travel with their event without having to be enumerated.
     *
     * @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    private function shiftDates(array $value, int $delta): array
    {
        if ($delta === 0) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->shiftDates($item, $delta);
                continue;
            }
            if (is_string($item)) {
                $shifted = $this->shiftDateString($item, $delta);
                if ($shifted !== null) {
                    $value[$key] = $shifted;
                }
            }
        }

        return $value;
    }

    /**
     * Returns the shifted string, or null when the value is not a date. The patterns are
     * anchored so ids, slugs and URLs that merely contain digits are left alone.
     */
    private function shiftDateString(string $value, int $delta): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date === false ? null : $this->move($date, $delta)->format('Y-m-d');
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}(:\d{2})?)(.*)$/', $value, $m) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
            return $date === false
                ? null
                : $this->move($date, $delta)->format('Y-m-d') . 'T' . $m[2] . $m[4];
        }

        return null;
    }

    private function move(DateTimeImmutable $date, int $delta): DateTimeImmutable
    {
        return $date->modify(($delta >= 0 ? '+' : '-') . abs($delta) . ' days');
    }

    private function daysBetween(string $from, string $to): int
    {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($a === false || $b === false) {
            return 0;
        }

        return (int) $a->diff($b)->format('%r%a');
    }

    /**
     * A series runs for as long as its events do, so its span is derived rather than
     * shifted — the events have already moved by the time this runs.
     *
     * @param array<int, array<string, mixed>> $series
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function rebuildSeriesSpans(array $series, array $events): array
    {
        $dateById = [];
        foreach ($events as $event) {
            $dateById[(string) $event['id']] = (string) ($event['date'] ?? '');
        }

        foreach ($series as $index => $entry) {
            $dates = [];
            foreach ($entry['eventIds'] ?? [] as $eventId) {
                $date = $dateById[(string) $eventId] ?? '';
                if ($date !== '') {
                    $dates[] = $date;
                }
            }
            if ($dates === []) {
                continue;
            }
            $series[$index]['startDate'] = min($dates);
            $series[$index]['endDate'] = max($dates);
        }

        return $series;
    }
}
