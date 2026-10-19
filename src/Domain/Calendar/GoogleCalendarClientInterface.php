<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Calendar;

/** R-17: Google Calendar API v3, events resource. Event shape = the API's own (summary, start, end, ...). */
interface GoogleCalendarClientInterface
{
    /**
     * @param array<string, mixed> $event
     * @return string the Google event id
     * @throws \RuntimeException on a non-2xx response or a transport failure
     */
    public function createEvent(string $calendarId, array $event): string;

    /**
     * @param array<string, mixed> $event
     * @throws \RuntimeException on a non-2xx response or a transport failure
     */
    public function updateEvent(string $calendarId, string $googleEventId, array $event): void;

    /**
     * Removes the calendar entry. An entry that is already gone (HTTP 404 / 410) counts as success.
     *
     * @throws \RuntimeException on any other non-2xx response or a transport failure
     */
    public function deleteEvent(string $calendarId, string $googleEventId): void;
}
