<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;

/**
 * Final pass over every seeded event, making the calendar tell one story.
 *
 * The seed data arrives from three places — the hand-written `001_demo.sql`, the bulk
 * generator, and the JSON fixtures — and only the fixtures get their dates re-anchored to
 * the day of the run. The other two carry dates that were written once and have since gone
 * past, leaving events marked `active` that happened months ago, finished races still
 * advertising open registration, and results hanging off races that have not been run.
 *
 * Rather than teach every seeder about today's date, this reconciles the finished state:
 * the date is the fact, and status, registration state and results are made to agree with
 * it. It runs against the whole table, so it keeps working as the seeders change.
 */
final class EventCoherence
{
    /** publication_status values that describe a published, publicly listed event. */
    private const PUBLISHED = ['published', 'finished', 'cancelled'];

    /**
     * @return array<string, int> what was corrected, by kind
     */
    public static function apply(PDO $pdo): array
    {
        $fixed = [];

        // An event whose day has passed is finished, whatever the seeder said. Same rule
        // as EventRepository::lazilyFinishPastEvents(), just applied eagerly here
        // over the whole seeded table instead of lazily on read.
        $fixed['marked_finished'] = self::run(
            $pdo,
            "UPDATE events SET publication_status = 'finished', registration_status = 'closed'
             WHERE publication_status = 'published' AND date < CURDATE()"
        );

        // And one still ahead of us has not finished yet. `cancelled` is excluded — it's
        // terminal and manual, a seeder saying `date > CURDATE()` doesn't undo it.
        $fixed['marked_upcoming'] = self::run(
            $pdo,
            "UPDATE events SET publication_status = 'published'
             WHERE publication_status = 'finished' AND date > CURDATE()"
        );

        // Registration cannot still be open once the race has been run.
        $fixed['registration_closed'] = self::run(
            $pdo,
            "UPDATE events SET registration_status = 'closed'
             WHERE date < CURDATE() AND registration_status = 'open'"
        );

        // Nor can it close after the event it is for.
        $fixed['deadline_clamped'] = self::run(
            $pdo,
            'UPDATE events SET registration_deadline = date
             WHERE registration_deadline IS NOT NULL AND registration_deadline > date'
        );

        // Results belong to races that have been run. A race happening today may already
        // have some, so only the future is cleared.
        $fixed['future_results_removed'] = self::run(
            $pdo,
            'DELETE r FROM results r JOIN events e ON e.id = r.event_id WHERE e.date > CURDATE()'
        );
        $fixed['future_team_results_removed'] = self::run(
            $pdo,
            'DELETE t FROM team_results t JOIN events e ON e.id = t.event_id WHERE e.date > CURDATE()'
        );

        // Only one event can be the one happening right now.
        self::run($pdo, 'UPDATE events SET is_live = 0 WHERE date <> CURDATE()');
        $fixed['live_today'] = self::run(
            $pdo,
            "UPDATE events SET is_live = 1
             WHERE date = CURDATE() AND publication_status IN ('published', 'finished')"
        );

        // Nobody can have entered an event that has not opened for entries.
        self::run(
            $pdo,
            "DELETE p FROM registration_payment_details p
             JOIN registrations r ON r.id = p.registration_id
             JOIN events e ON e.id = r.event_id
             WHERE e.registration_status = 'none'"
        );
        $fixed['premature_entries_removed'] = self::run(
            $pdo,
            "DELETE r FROM registrations r JOIN events e ON e.id = r.event_id
             WHERE e.registration_status = 'none'"
        );

        $fixed['galleries_added'] = self::addMissingGalleries($pdo);

        return array_filter($fixed, static fn (int $count): bool => $count > 0);
    }

    /**
     * A finished race with no photos anywhere is the one thing the gallery screens cannot
     * be tried out on, so every past event gets at least one album.
     */
    private static function addMissingGalleries(PDO $pdo): int
    {
        $stmt = $pdo->query(
            "SELECT e.id, e.slug FROM events e
             WHERE e.date < CURDATE()
               AND e.publication_status IN ('" . implode("','", self::PUBLISHED) . "')
               AND NOT EXISTS (SELECT 1 FROM event_gallery_albums g WHERE g.event_id = e.id)"
        );

        $insert = $pdo->prepare(
            'INSERT INTO event_gallery_albums (id, event_id, name, url, platform, photographer, sort_order)
             VALUES (:id, :event_id, :name, :url, :platform, :photographer, 0)'
        );

        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $insert->execute([
                'id' => 'ga-auto-' . substr(sha1((string) $event['id']), 0, 22),
                'event_id' => $event['id'],
                'name' => 'Oficjalna galeria zdjec',
                'url' => 'https://photos.google.com/share/' . $event['slug'],
                'platform' => 'google_photos',
                'photographer' => 'Studio Biegowe',
            ]);
            $count++;
        }

        return $count;
    }

    private static function run(PDO $pdo, string $sql): int
    {
        return (int) $pdo->exec($sql);
    }
}
