<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Calendar;

/** R-17: Platform Event -> Google Calendar event resource (title, date, place, link to the event page). */
final class GoogleCalendarEventMapper
{
    /**
     * @param array<string, mixed> $event EventRepository::find() shape
     * @return array<string, mixed>|null null when the Event has no usable date
     */
    public static function map(array $event, string $timeZone, string $appUrl): ?array
    {
        $start = trim((string) ($event['startDate'] ?? ''));
        if ($start === '') {
            $start = trim((string) ($event['date'] ?? ''));
        }
        if ($start === '') {
            return null;
        }
        $end = trim((string) ($event['endDate'] ?? ''));

        try {
            $tz = new \DateTimeZone($timeZone !== '' ? $timeZone : 'Europe/Warsaw');
            $startAt = new \DateTimeImmutable($start, $tz);
            $endAt = $end !== '' ? new \DateTimeImmutable($end, $tz) : null;
        } catch (\Throwable) {
            return null;
        }

        $timed = str_contains($start, 'T') || preg_match('/\d{1,2}:\d{2}/', $start) === 1;
        if ($timed) {
            $endAt = $endAt !== null && $endAt > $startAt ? $endAt : $startAt->modify('+3 hours');
            $startPart = ['dateTime' => $startAt->format(DATE_ATOM), 'timeZone' => $tz->getName()];
            $endPart = ['dateTime' => $endAt->format(DATE_ATOM), 'timeZone' => $tz->getName()];
        } else {
            // All-day events: Google's end date is exclusive.
            $last = $endAt !== null && $endAt >= $startAt ? $endAt : $startAt;
            $startPart = ['date' => $startAt->format('Y-m-d')];
            $endPart = ['date' => $last->modify('+1 day')->format('Y-m-d')];
        }

        $place = implode(', ', array_filter([
            trim((string) ($event['location'] ?? '')),
            trim((string) ($event['city'] ?? '')),
        ], static fn (string $v): bool => $v !== ''));
        $url = rtrim($appUrl, '/') . '/events/' . rawurlencode((string) ($event['slug'] ?? ''));

        return [
            'summary' => (string) ($event['name'] ?? ''),
            'location' => $place,
            'description' => $url,
            'source' => ['title' => (string) ($event['name'] ?? ''), 'url' => $url],
            'start' => $startPart,
            'end' => $endPart,
            // The admin panel promises "1 dzień i 1 tydzień przed".
            'reminders' => ['useDefault' => false, 'overrides' => [
                ['method' => 'popup', 'minutes' => 1440],
                ['method' => 'popup', 'minutes' => 10080],
            ]],
        ];
    }
}
