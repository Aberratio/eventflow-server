<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use PDO;
use RuntimeException;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Support\QrCode;
use ZmierzymyCzas\Support\Uuid;

/**
 * Loads the event fixtures from database/seeders/data/events.json into the database.
 *
 * These events used to live in the frontend as TypeScript literals, which meant the app
 * could only reach them through a mock adapter and nothing about them existed behind the
 * API. The JSON file is now the fixture source and the database is where the app reads
 * them from.
 *
 * Events go in through EventRepository so the fixture shape and the API shape cannot
 * drift apart: whatever the repository persists for a real event, it persists for these.
 * Rows that only hang off an event are written directly, having no comparable write path.
 */
final class EventFixtureImporter
{
    /** Same hash as database/seeders/001_demo.sql, so every demo login shares one password. */
    private const DEMO_PASSWORD_HASH = '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK';

    /**
     * The fixtures and 001_demo.sql describe some of the same people under different ids
     * — same name, same email, which `users.email` will not allow twice. These are the
     * duplicates, mapped onto the id 001_demo.sql already uses. Every reference in the
     * fixture data is rewritten through this map before anything is written.
     */
    private const USER_ALIASES = [
        'p1' => 'u-participant',      // jan@example.com
        'p2' => 'u-anna',             // anna@example.com
        'p3' => 'u-piotr',            // piotr@example.com
        'p4' => 'u-kasia',            // kasia@example.com
        'mod1' => 'u-moderator',      // monika@zmierzymyczas.pl
        'o1' => 'u-organizer',        // marek@timing.pl
        'a1' => 'u-admin',            // admin@zmierzymyczas.pl
        'sa1' => 'u-superadmin',      // superadmin@zmierzymyczas.pl
    ];

    /** Accounts 001_demo.sql already creates with these exact ids. */
    private const USERS_OWNED_BY_DEMO_SQL = [
        'test-participant',
        'test-organizer',
        'test-moderator',
        'test-admin',
        'test-superadmin',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function dataFile(): string
    {
        return dirname(__DIR__, 2) . '/database/seeders/data/events.json';
    }

    /**
     * @return array<string, int> rows written per table
     */
    public function import(?string $file = null): array
    {
        $file ??= self::dataFile();
        if (!is_file($file)) {
            throw new RuntimeException("Fixture file not found: {$file}");
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Fixture file is not a JSON object: {$file}");
        }

        $data = $this->applyUserAliases($data);

        // The fixtures carry absolute dates and only sketch their surroundings, so both are
        // rebuilt around the day of the seed run: dates re-anchored to the current season,
        // then each event given the entrants, results and gallery its position implies.
        $data = (new FixtureCalendar())->reanchor($data);
        $data = (new FixtureFieldFiller())->fill($data);

        $written = [];
        $written['users'] = $this->importUsers($data['users'] ?? [], $data['registrations'] ?? []);
        $written['series'] = $this->importSeries($data['series'] ?? []);
        $written['events'] = $this->importEvents($data['events'] ?? []);
        $written += $this->importRegistrations($data['registrations'] ?? []);
        $written['results'] = $this->importResults($data['results'] ?? []);
        $written['team_results'] = $this->importTeamResults($data['teamResults'] ?? []);
        $written['event_result_documents'] = $this->importResultDocuments($data['resultDocuments'] ?? []);
        $written['virtual_result_submissions'] = $this->importVirtualSubmissions(
            $data['virtualResultSubmissions'] ?? [],
            $data['registrations'] ?? []
        );

        return array_filter($written, static fn (int $count): bool => $count > 0);
    }

    /**
     * Rewrites every id in USER_ALIASES to the id 001_demo.sql uses, wherever a user is
     * referenced. Done before anything is written so the fixture graph stays internally
     * consistent — a registration must not survive its participant being merged away.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyUserAliases(array $data): array
    {
        $fields = ['userId', 'organizerId', 'approvedBy', 'publishedBy', 'createdBy', 'reviewedBy'];

        $rewrite = static function (array $rows) use ($fields): array {
            foreach ($rows as $index => $row) {
                foreach ($fields as $field) {
                    $value = $row[$field] ?? null;
                    if (is_string($value) && isset(self::USER_ALIASES[$value])) {
                        $rows[$index][$field] = self::USER_ALIASES[$value];
                    }
                }
            }
            return $rows;
        };

        foreach (['events', 'series', 'registrations', 'results', 'virtualResultSubmissions'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = $rewrite($data[$key]);
            }
        }

        // The aliased accounts already exist; drop their fixture copies entirely.
        $data['users'] = array_values(array_filter(
            $data['users'] ?? [],
            static fn (array $user): bool => !isset(self::USER_ALIASES[(string) $user['id']])
                && !in_array((string) $user['id'], self::USERS_OWNED_BY_DEMO_SQL, true)
        ));

        return $data;
    }

    /**
     * Fixture users, plus an account for every participant that only a registration
     * mentions. Without them those registrations would point at users that do not exist.
     *
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $registrations
     */
    private function importUsers(array $users, array $registrations): int
    {
        $known = $this->existingUserIds();
        foreach ($users as $user) {
            $known[(string) $user['id']] = true;
        }

        foreach ($registrations as $registration) {
            $id = (string) ($registration['userId'] ?? '');
            if ($id === '' || isset($known[$id])) {
                continue;
            }
            $known[$id] = true;
            $users[] = [
                'id' => $id,
                'name' => (string) ($registration['userName'] ?? $id),
                'email' => (string) ($registration['userEmail'] ?? $id . '@demo.test'),
                'role' => 'participant',
            ];
        }

        $present = $this->existingUserIds();

        $count = 0;
        foreach ($users as $user) {
            if (isset($present[(string) $user['id']])) {
                continue;
            }
            $role = (string) ($user['role'] ?? 'participant');
            $this->insert('users', [
                'id' => (string) $user['id'],
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'password_hash' => self::DEMO_PASSWORD_HASH,
                'role' => $role,
                'phone' => $user['phone'] ?? null,
                'birth_year' => $user['birthYear'] ?? null,
                'gender' => $user['gender'] ?? null,
                'club' => $user['club'] ?? null,
                'company_name' => $user['companyName'] ?? null,
                'status' => (string) ($user['status'] ?? 'active'),
                'event_limit' => (int) ($user['eventLimit'] ?? ($role === 'organizer' ? 11 : 0)),
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<int, array<string, mixed>> $series */
    private function importSeries(array $series): int
    {
        $count = 0;
        foreach ($series as $entry) {
            $this->insert('series', [
                'id' => (string) $entry['id'],
                'name' => (string) $entry['name'],
                'slug' => (string) $entry['slug'],
                'type' => (string) $entry['type'],
                'description' => (string) ($entry['description'] ?? ''),
                'organizer_id' => (string) ($entry['organizerId'] ?? ''),
                'organizer_name' => (string) ($entry['organizerName'] ?? ''),
                'start_date' => $entry['startDate'] ?? null,
                'end_date' => $entry['endDate'] ?? null,
                'status' => (string) ($entry['status'] ?? 'active'),
            ]);
            $count++;

            foreach (array_values($entry['eventIds'] ?? []) as $index => $eventId) {
                $this->insert('series_events', [
                    'series_id' => (string) $entry['id'],
                    'event_id' => (string) $eventId,
                    'sort_order' => $index,
                ]);
            }
        }

        return $count;
    }

    /**
     * Gives every published event still missing them a field of entrants, and every
     * finished one its results.
     *
     * The JSON fixtures are handled while they are still JSON, but `001_demo.sql` and the
     * bulk generator write straight to the database, and their events drift as their
     * hard-coded dates age past. This runs over whatever is actually in the table, so a
     * finished race always has results to show and an open one always has a start list.
     *
     * @return array<string, int> rows written per table
     */
    public function topUpFromDatabase(): array
    {
        $repository = new EventRepository($this->pdo);

        $candidates = $this->pdo->query(
            "SELECT e.id,
                    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id) AS regs,
                    (SELECT COUNT(*) FROM results s WHERE s.event_id = e.id) AS res,
                    (SELECT COUNT(*) FROM event_distances d WHERE d.event_id = e.id) AS dists
             FROM events e
             WHERE e.publication_status IN ('published', 'finished', 'cancelled')
               AND (e.registration_status <> 'none' OR e.registration_status IS NULL)"
        )->fetchAll(PDO::FETCH_ASSOC);

        $written = ['users' => 0, 'registrations' => 0, 'registration_settlement_items' => 0, 'registration_payment_details' => 0, 'results' => 0];

        foreach ($candidates as $row) {
            if ((int) $row['dists'] === 0) {
                continue;   // Nothing to enter; leave it alone.
            }
            $event = $repository->find((string) $row['id']);
            if ($event === null) {
                continue;
            }

            $finished = (string) $event['publicationStatus'] === 'finished';
            $needsEntrants = (int) $row['regs'] < 5;
            // A finished event needs a result for every entrant, not merely some results.
            $needsResults = $finished && (int) $row['res'] < (int) $row['regs'];

            if (!$needsEntrants && !$needsResults) {
                continue;
            }

            $filler = new FixtureFieldFiller();
            $startList = $this->registrationsOf((string) $row['id']);

            // Entrants are only ever invented for an event that has next to none. Topping
            // up an event that already has a field would generate ids the fixture import
            // has already used for it.
            if ($needsEntrants) {
                $filled = $filler->fill([
                    'events' => [$event],
                    'registrations' => [],
                    'results' => [],
                    'users' => [],
                ]);

                $written['users'] += $this->importUsers($filled['users'], []);
                $counts = $this->importRegistrations($filled['registrations']);
                $written['registrations'] += $counts['registrations'];
                $written['registration_settlement_items'] += $counts['registration_settlement_items'];
                $written['registration_payment_details'] += $counts['registration_payment_details'];
                $startList = array_merge($startList, $filled['registrations']);
            }

            // A finished race needs a result for everyone on its start list, not merely
            // some results. Only the missing ones are generated, so anything hand-written
            // stays exactly as it was.
            if ($finished) {
                $already = $this->userIdsWithResult((string) $row['id']);
                $missing = array_values(array_filter(
                    $filler->resultsForRegistrations($event, $startList),
                    static fn (array $result): bool => !isset($already[(string) $result['userId']])
                ));
                $written['results'] += $this->importResults($missing);
            }
        }

        return array_filter($written, static fn (int $count): bool => $count > 0);
    }

    /**
     * @return array<string, true>
     */
    private function userIdsWithResult(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM results WHERE event_id = :eventId');
        $stmt->execute(['eventId' => $eventId]);

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(string) $id] = true;
        }

        return $ids;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function registrationsOf(string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, event_id AS eventId, event_name AS eventName,
                    user_id AS userId, user_name AS userName, user_email AS userEmail,
                    distance_id AS distanceId, distance_name AS distanceName,
                    category, bib_number AS bibNumber, status
             FROM registrations WHERE event_id = :eventId'
        );
        $stmt->execute(['eventId' => $eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, true> ids of users already in the database
     */
    private function existingUserIds(): array
    {
        $ids = [];
        foreach ($this->pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(string) $id] = true;
        }

        return $ids;
    }

    /** @param array<int, array<string, mixed>> $events */
    private function importEvents(array $events): int
    {
        $repository = new EventRepository($this->pdo);
        $takenSlugs = [];
        foreach ($this->pdo->query('SELECT slug FROM events')->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $takenSlugs[(string) $slug] = true;
        }

        $count = 0;
        foreach ($events as $event) {
            $id = (string) $event['id'];
            unset($event['id']);

            // A handful of fixtures reuse a slug that 001_demo.sql already claimed, and
            // `events.slug` is unique. Both events are worth keeping, so the fixture gets
            // a distinct slug rather than being dropped.
            $slug = (string) ($event['slug'] ?? $id);
            if (isset($takenSlugs[$slug])) {
                $slug .= '-' . $id;
            }
            $takenSlugs[$slug] = true;
            $event['slug'] = $slug;

            // Fixture events share a small set of payment-method ids (pm1..pm4), but the
            // column is a primary key. Nothing references these ids, so namespacing them
            // per event is safe; every other child kind is already globally unique and is
            // left alone because registrations and results point at those ids.
            foreach (array_keys($event['paymentMethods'] ?? []) as $index) {
                $method = $event['paymentMethods'][$index];
                $event['paymentMethods'][$index]['id'] = $id . '-' . ($method['id'] ?? $index);
            }

            $repository->createWithId($id, $event);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $registrations
     * @return array<string, int>
     */
    private function importRegistrations(array $registrations): array
    {
        $counts = [
            'registrations' => 0,
            'registration_settlement_items' => 0,
            'registration_payment_details' => 0,
            'registration_merchandise_orders' => 0,
        ];

        foreach ($registrations as $registration) {
            $id = (string) $registration['id'];
            $registeredAt = (string) ($registration['registeredAt'] ?? '');

            $this->insert('registrations', [
                'id' => $id,
                'event_id' => (string) $registration['eventId'],
                'event_name' => (string) ($registration['eventName'] ?? ''),
                'user_id' => (string) $registration['userId'],
                'user_name' => (string) ($registration['userName'] ?? ''),
                'user_email' => (string) ($registration['userEmail'] ?? ''),
                'distance_id' => (string) ($registration['distanceId'] ?? ''),
                'distance_name' => (string) ($registration['distanceName'] ?? ''),
                'category' => (string) ($registration['category'] ?? ''),
                'registered_at' => $registeredAt,
                'status' => (string) ($registration['status'] ?? 'pending'),
                // E1.11: kod startowy, tak jak generuje go RegistrationRepository::create().
                // Generowany tu, a nie brany z fixtury: `qr_code` jest NOT NULL bez DEFAULT
                // i UNIQUE, a ten import biegnie bez STRICT_TRANS_TABLES, gdzie pominiecie
                // kolumny to nie blad, tylko ciche '' w kazdym wierszu -- czyli 1062 na
                // drugim Zapisie. Fixtura i tak nie moglaby go nosic sensownie: staly
                // literal w events.json bylby tym samym tokenem w kazdej bazie.
                'qr_code' => QrCode::startCode(),
                'bib_number' => $registration['bibNumber'] ?? null,
                'team_name' => $registration['teamName'] ?? null,
                'discount_code' => $registration['discountCode'] ?? null,
            ]);
            $counts['registrations']++;
            $counts['registration_settlement_items'] += $this->importSettlementItems($id, $registration, $registeredAt);

            $details = $registration['paymentDetails'] ?? null;
            if (is_array($details)) {
                $this->insert('registration_payment_details', [
                    'registration_id' => $id,
                    'start_fee_paid' => (int) (bool) ($details['startFeePaid'] ?? false),
                    'start_fee_paid_at' => $details['startFeePaidAt'] ?? null,
                    'start_fee_amount' => $details['startFeeAmount'] ?? 0,
                    'insurance_paid' => (int) (bool) ($details['insurancePaid'] ?? false),
                    'insurance_paid_at' => $details['insurancePaidAt'] ?? null,
                    'insurance_amount' => $details['insuranceAmount'] ?? null,
                    'merchandise_payments' => $this->json($details['merchandisePayments'] ?? []),
                ]);
                $counts['registration_payment_details']++;
            }

            foreach (array_values($registration['merchandiseOrders'] ?? []) as $index => $order) {
                $this->insert('registration_merchandise_orders', [
                    'id' => (string) ($order['id'] ?? $id . '-merch-' . $index),
                    'registration_id' => $id,
                    'merchandise_id' => (string) ($order['merchandiseId'] ?? ''),
                    'merchandise_name' => (string) ($order['merchandiseName'] ?? ''),
                    'size' => $order['size'] ?? null,
                    'color' => $order['color'] ?? null,
                    'color_hex' => $order['colorHex'] ?? null,
                    'quantity' => (int) ($order['quantity'] ?? 1),
                    'price' => $order['price'] ?? 0,
                ]);
                $counts['registration_merchandise_orders']++;
            }
        }

        return $counts;
    }

    /**
     * Fixtures still describe the old flat `pricePaid` / `discountAmount` /
     * `insurancePaid` registration fields (E0.3 dropped the columns behind them).
     * Translates them into Rozliczenie line items instead of writing dead columns.
     * The gross start fee is reconstructed as pricePaid + discountAmount, mirroring
     * RegistrationRepository::pricingForRegistration's `pricePaid = price - discount`.
     *
     * @param array<string, mixed> $registration
     */
    private function importSettlementItems(string $registrationId, array $registration, string $registeredAt): int
    {
        $count = 0;
        $pricePaid = $registration['pricePaid'] ?? null;
        $discountAmount = $registration['discountAmount'] ?? null;
        $insurancePaid = $registration['insurancePaid'] ?? null;

        if ($pricePaid !== null) {
            $this->insert('registration_settlement_items', [
                'id' => Uuid::v4(),
                'registration_id' => $registrationId,
                'name' => 'Opłata startowa',
                'basis' => 'distance_price',
                'amount' => round((float) $pricePaid + (float) ($discountAmount ?? 0), 2),
                'frozen_at' => $registeredAt,
                'sort_order' => 0,
            ]);
            $count++;

            if ($discountAmount !== null && (float) $discountAmount !== 0.0) {
                $code = (string) ($registration['discountCode'] ?? '');
                $this->insert('registration_settlement_items', [
                    'id' => Uuid::v4(),
                    'registration_id' => $registrationId,
                    'name' => 'Kod rabatowy' . ($code !== '' ? ' ' . $code : ''),
                    'basis' => 'discount_code',
                    'amount' => -abs((float) $discountAmount),
                    'frozen_at' => $registeredAt,
                    'sort_order' => 1,
                ]);
                $count++;
            }
        }

        if ($insurancePaid !== null && is_numeric($insurancePaid)) {
            $this->insert('registration_settlement_items', [
                'id' => Uuid::v4(),
                'registration_id' => $registrationId,
                'name' => 'Ubezpieczenie NNW',
                'basis' => 'insurance',
                'amount' => (float) $insurancePaid,
                'frozen_at' => $registeredAt,
                'sort_order' => 2,
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<int, array<string, mixed>> $results */
    private function importResults(array $results): int
    {
        $count = 0;
        foreach ($results as $result) {
            $this->insert('results', [
                'id' => (string) $result['id'],
                'event_id' => (string) $result['eventId'],
                'event_name' => (string) ($result['eventName'] ?? ''),
                'distance_id' => (string) ($result['distanceId'] ?? ''),
                'distance_name' => (string) ($result['distanceName'] ?? ''),
                'user_id' => (string) ($result['userId'] ?? ''),
                'user_name' => (string) ($result['userName'] ?? ''),
                'bib_number' => (string) ($result['bibNumber'] ?? ''),
                'category' => (string) ($result['category'] ?? ''),
                'time' => (string) ($result['time'] ?? ''),
                'time_in_seconds' => (int) ($result['timeInSeconds'] ?? 0),
                'position' => (int) ($result['position'] ?? 0),
                'category_position' => (int) ($result['categoryPosition'] ?? 0),
                'status' => (string) ($result['status'] ?? 'finished'),
                'gender' => $result['gender'] ?? null,
                'birth_year' => $result['birthYear'] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<int, array<string, mixed>> $teamResults */
    private function importTeamResults(array $teamResults): int
    {
        $count = 0;
        foreach ($teamResults as $team) {
            $this->insert('team_results', [
                'id' => (string) $team['id'],
                'event_id' => (string) $team['eventId'],
                'distance_id' => (string) ($team['distanceId'] ?? ''),
                'distance_name' => (string) ($team['distanceName'] ?? ''),
                'team_name' => (string) ($team['teamName'] ?? ''),
                'members' => $this->json($team['members'] ?? []),
                'total_time' => (string) ($team['totalTime'] ?? ''),
                'total_time_in_seconds' => (int) ($team['totalTimeInSeconds'] ?? 0),
                'position' => (int) ($team['position'] ?? 0),
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<int, array<string, mixed>> $documents */
    private function importResultDocuments(array $documents): int
    {
        $count = 0;
        foreach (array_values($documents) as $index => $document) {
            $this->insert('event_result_documents', [
                'id' => (string) $document['id'],
                'event_id' => (string) $document['eventId'],
                'name' => (string) ($document['name'] ?? ''),
                'type' => (string) ($document['type'] ?? 'open'),
                'distance_id' => $document['distanceId'] ?? null,
                'distance_name' => $document['distanceName'] ?? null,
                'file_url' => (string) ($document['fileUrl'] ?? ''),
                'uploaded_at' => (string) ($document['uploadedAt'] ?? ''),
                'uploaded_by' => (string) ($document['uploadedBy'] ?? ''),
                'sort_order' => $index,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * The fixtures predate `registration_id` on this table, so the matching registration
     * is looked up by event and user; a submission without one keeps an empty reference.
     *
     * @param array<int, array<string, mixed>> $submissions
     * @param array<int, array<string, mixed>> $registrations
     */
    private function importVirtualSubmissions(array $submissions, array $registrations): int
    {
        $byEventAndUser = [];
        foreach ($registrations as $registration) {
            $key = ($registration['eventId'] ?? '') . '|' . ($registration['userId'] ?? '');
            $byEventAndUser[$key] ??= (string) $registration['id'];
        }

        $count = 0;
        foreach ($submissions as $submission) {
            $key = ($submission['eventId'] ?? '') . '|' . ($submission['userId'] ?? '');

            $this->insert('virtual_result_submissions', [
                'id' => (string) $submission['id'],
                'event_id' => (string) $submission['eventId'],
                'event_name' => (string) ($submission['eventName'] ?? ''),
                'distance_id' => (string) ($submission['distanceId'] ?? ''),
                'distance_name' => (string) ($submission['distanceName'] ?? ''),
                'registration_id' => $byEventAndUser[$key] ?? '',
                'user_id' => (string) ($submission['userId'] ?? ''),
                'user_name' => (string) ($submission['userName'] ?? ''),
                'user_email' => (string) ($submission['userEmail'] ?? ''),
                'submitted_time' => (string) ($submission['submittedTime'] ?? ''),
                'submitted_time_in_seconds' => (int) ($submission['submittedTimeInSeconds'] ?? 0),
                'proof_url' => $submission['proofUrl'] ?? null,
                'notes' => $submission['notes'] ?? null,
                'submitted_at' => (string) ($submission['submittedAt'] ?? ''),
                'status' => (string) ($submission['status'] ?? 'pending'),
                'reviewed_at' => $submission['reviewedAt'] ?? null,
                'reviewed_by' => $submission['reviewedBy'] ?? null,
                'rejection_reason' => $submission['rejectionReason'] ?? null,
            ]);
            $count++;
        }

        return $count;
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );

        $this->pdo->prepare($sql)->execute($row);
    }
}
