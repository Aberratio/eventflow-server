# Google Calendar integration

After an Event (Wydarzenie) is approved, the platform adds it to a single designated Google calendar (title, date,
location, link to the event page). The integration is **disabled by default** and sends nothing until
three conditions are met: (1) the server has a service-account key file, (2) an administrator enabled the
integration in the panel, (3) a calendar ID was provided.

## What the client does (one-off)

1. In [Google Cloud Console](https://console.cloud.google.com/) create a project (or pick an existing one)
   and enable the **Google Calendar API** (APIs & Services -> Library).
2. IAM & Admin -> Service Accounts -> **Create service account** (e.g. `zmierzymyczas-kalendarz`).
   No project role needs to be granted.
3. On the service account: Keys -> Add key -> **JSON**. The downloaded file is the key (a secret - do not send it by
   email or commit it to the repository; hand it to the server administrator over a secure channel).
4. In Google Calendar (calendar.google.com), on the target calendar: Settings and sharing ->
   **Share with specific people** -> add the service account's email address (`...@...iam.gserviceaccount.com`,
   the `client_email` field of the JSON file) with the **Make changes to events** permission.
5. From the same screen copy the **Calendar ID** (the "Integrate calendar" section; for the
   primary calendar it is the Gmail address).

Alternative: a dedicated Google account for the platform that owns the calendar.
A service account is recommended because it needs no password or interactive login.

## What the server administrator does

1. Place the JSON file outside the public directory, e.g. `/home/USER/secrets/google-calendar.json`
   (permissions 600).
2. In `.env` set `GOOGLE_CALENDAR_SERVICE_ACCOUNT_FILE=/path/to/file.json`.
3. Make sure the scheduler is running (`bin/scheduler.php`, see `docs/scheduler.md`) - the
   `calendar.outbox_retry` job actually sends the entries.

The key never goes into the database or API responses; the panel only shows whether a key is configured.

## What the platform administrator does

Admin panel -> Platform settings -> Google Calendar integration: enter the calendar ID, optionally the
time zone (default `Europe/Warsaw`) and enable the integration.

## Behavior

- Approving an Event (`POST /events/{id}/approve`) only appends an entry to the
  `google_calendar_outbox` queue; a Google error never blocks the approval.
- The `calendar.outbox_retry` job sends the entries; on failure it retries with increasing delay
  (5 min, 10 min, ... up to 8 attempts, then `abandoned`).
- The Google entry id is stored on the Event (`events.google_calendar_event_id`). The manual
  "Wyślij do kalendarza" action (`POST /calendar/events`, body `{ "id": "<event id>" }`) updates the
  existing entry instead of creating a duplicate.
- Not implemented: automatically updating the entry when the Event date changes, or removing the
  entry after cancellation - for now the manual "Wyślij do kalendarza" action (update) covers this.
