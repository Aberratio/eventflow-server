<?php

declare(strict_types=1);

/**
 * Fixture for the ZC <-> BZ end-to-end runner (PP.43). Runs inside the ZC checkout the
 * runner owns (its `.env` points at the e2e database) and prints one JSON document.
 *
 * Built through the repositories rather than raw SQL so NOT NULL columns, generated
 * codes (`pqr_` registration code, `zqr_` competitor identity code) and defaults follow
 * the product code and cannot drift away from the schema.
 *
 * Creates, on a database that is migrated but not seeded:
 *  - the demo accounts (only the users statement of database/seeders/001_demo.sql; the
 *    full seed also generates bulk events, which takes minutes and is irrelevant here);
 *  - one published event with the BZ integration still DISABLED (the scenario enables it
 *    over HTTP), organized by u-organizer;
 *  - three registrations A (u-participant, will withdraw), B (scanned by zqr_), C (scanned by pqr_).
 */

use ZmierzymyCzas\Database\Connection;
use ZmierzymyCzas\Database\Migrator;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;

require __DIR__ . '/../../../vendor/autoload.php';

Env::load(__DIR__ . '/../../../.env');

$database = (string) Env::get('DB_DATABASE', '');
if (preg_match('/_e2e$/', $database) !== 1) {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must end with _e2e (got \"{$database}\").\n");
    exit(2);
}

$pdo = Connection::fromEnv();

// ---- demo accounts (users statement only)
$seedFile = (string) file_get_contents(__DIR__ . '/../../../database/seeders/001_demo.sql');
$usersLoaded = 0;
foreach (Migrator::statements($seedFile) as $statement) {
    if (preg_match('/^\s*INSERT\s+INTO\s+users\b/i', $statement) === 1) {
        $pdo->exec($statement);
        $usersLoaded++;
    }
}
if ($usersLoaded !== 1) {
    fwrite(STDERR, "Expected exactly one users INSERT in 001_demo.sql, found {$usersLoaded}.\n");
    exit(2);
}

$events = new EventRepository($pdo);
$competitors = new CompetitorRepository($pdo);
$registrations = new RegistrationRepository($pdo, null, null, null);

$slug = 'e2e-bz-' . bin2hex(random_bytes(3));
$event = $events->create([
    'name' => 'E2E BZ Contract Run',
    'slug' => $slug,
    'discipline' => 'running',
    'date' => date('Y-m-d', strtotime('+14 days')),
    'location' => 'Stadion E2E',
    'city' => 'Gniezno',
    'description' => '<p>End-to-end contract fixture</p>',
    'imageUrl' => 'https://example.test/e2e.jpg',
    'organizerId' => 'u-organizer',
    'organizerName' => 'Marek Nowak',
    'status' => 'published',
    'registrationDeadline' => date('Y-m-d', strtotime('+7 days')),
    'paymentConfig' => ['type' => 'admin_gateway'],
    'bzIntegrationEnabled' => false,
    'distances' => [
        [
            'id' => $slug . '-10k',
            'name' => '10 km',
            'length' => 10,
            'unit' => 'km',
            'categories' => ['Open'],
            'ageCategories' => [],
            'specialCategories' => [],
            'limit' => 100,
            'registeredCount' => 0,
            'price' => 80,
            'routeFiles' => [],
        ],
    ],
]);
$eventId = (string) $event['id'];

$people = [
    'A' => ['firstName' => 'E2E Alfa', 'lastName' => 'Wycofany', 'userId' => 'u-participant', 'userName' => 'Jan Kowalski', 'email' => 'jan@example.com'],
    'B' => ['firstName' => 'E2E Beta', 'lastName' => 'Skan Zqr', 'userId' => 'e2e-user-b', 'userName' => 'E2E Beta Skan Zqr', 'email' => 'e2e-beta@example.test'],
    'C' => ['firstName' => 'E2E Gamma', 'lastName' => 'Skan Pqr', 'userId' => 'e2e-user-c', 'userName' => 'E2E Gamma Skan Pqr', 'email' => 'e2e-gamma@example.test'],
];

$out = ['eventId' => $eventId];
foreach ($people as $key => $person) {
    $competitor = $competitors->create([
        'firstName' => $person['firstName'],
        'lastName' => $person['lastName'],
    ], skipDuplicateCheck: true);

    $registration = $registrations->create([
        'eventId' => $eventId,
        'eventName' => $event['name'],
        'competitorId' => $competitor['id'],
        'userId' => $person['userId'],
        'userName' => $person['userName'],
        'userEmail' => $person['email'],
        'distanceId' => $event['distances'][0]['id'],
        'distanceName' => $event['distances'][0]['name'],
        'category' => 'Open',
    ]);

    $out[$key] = [
        'registrationId' => (string) $registration['id'],
        'competitorId' => (string) $competitor['id'],
        'qrCode' => (string) ($registration['qrCode'] ?? ''),
        'identityCode' => (string) ($competitor['identityCode'] ?? ''),
    ];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
