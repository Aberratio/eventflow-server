<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Database;

use DateTimeImmutable;

/**
 * Fills in what the fixtures leave out, so that every event is a believable example of
 * whatever it is meant to be.
 *
 * The fixtures were written to exercise the UI, not to be a plausible season: of nineteen
 * finished events only three carried results and two a gallery, and most upcoming events
 * had no entrants at all. A finished event with no results is not a useful thing to click
 * on, so this pass gives each event the surroundings its position in the calendar implies:
 *
 *   finished   closed registration, a full field, results with positions, a photo gallery
 *   upcoming   most open with a partly filled field; the rest not open for entries yet
 *   drafts     left bare, which is what an unpublished event looks like
 *
 * Anything a fixture already specifies is kept — this only fills gaps. Generation is
 * seeded from the event id, so re-running the seed produces the same field of runners.
 */
final class FixtureFieldFiller
{
    private const FIRST_NAMES_M = ['Adam', 'Bartosz', 'Cezary', 'Damian', 'Emil', 'Filip', 'Grzegorz',
        'Hubert', 'Igor', 'Jakub', 'Kamil', 'Leszek', 'Marek', 'Norbert', 'Oskar', 'Pawel', 'Rafal',
        'Sebastian', 'Tomasz', 'Wiktor', 'Zenon', 'Artur', 'Dawid', 'Eryk', 'Konrad', 'Mateusz'];

    private const FIRST_NAMES_F = ['Anna', 'Beata', 'Celina', 'Dorota', 'Ewa', 'Gabriela', 'Hanna',
        'Irena', 'Joanna', 'Karolina', 'Lidia', 'Marta', 'Natalia', 'Olga', 'Patrycja', 'Sandra',
        'Teresa', 'Urszula', 'Weronika', 'Zuzanna', 'Agnieszka', 'Barbara', 'Elzbieta', 'Kinga'];

    private const LAST_NAMES = ['Kowalski', 'Nowak', 'Wisniewski', 'Wojcik', 'Kaminski', 'Lewandowski',
        'Zielinski', 'Szymanski', 'Wozniak', 'Kozlowski', 'Jankowski', 'Mazur', 'Kwiatkowski',
        'Krawczyk', 'Grabowski', 'Nowakowski', 'Pawlowski', 'Adamczyk', 'Dudek', 'Zajac',
        'Wieczorek', 'Jablonski', 'Krol', 'Majewski', 'Olszewski', 'Jaworski', 'Wrobel', 'Stepien'];

    private const CLUBS = ['Biegacze Warszawy', 'Run Club Lodz', 'Tri Team Gdansk', 'Rowerowy Krakow',
        'Athletic Poznan', 'Sportowi Wroclaw', 'Maraton Lublin', 'UKS Biegun', 'Klub Biegacza Silesia',
        'Trojmiejski Klub Biegacza', 'Biegiem przez Polske'];

    private const GALLERY_PLATFORMS = [
        ['platform' => 'google_photos', 'host' => 'https://photos.google.com/share/'],
        ['platform' => 'flickr', 'host' => 'https://flickr.com/groups/'],
        ['platform' => 'facebook', 'host' => 'https://facebook.com/media/set/'],
    ];

    private const PHOTOGRAPHERS = ['Studio Biegowe', 'FotoSport', 'Maraton Foto', 'Objektyw Sportowy'];

    /** Pace in seconds per kilometre, by discipline, and how much runners vary. */
    private const PACE = [
        'running' => [330, 55],
        'cycling' => [110, 20],
        'triathlon' => [280, 45],
        'multisport' => [300, 50],
    ];

    private int $participantSeq = 0;

    private string $currentEvent = '';

    public function __construct(private readonly DateTimeImmutable $today = new DateTimeImmutable('today'))
    {
    }

    /**
     * @param array<string, mixed> $data the whole fixture payload, already re-anchored
     * @return array<string, mixed>
     */
    public function fill(array $data): array
    {
        $registrationsByEvent = [];
        foreach ($data['registrations'] ?? [] as $registration) {
            $registrationsByEvent[(string) ($registration['eventId'] ?? '')][] = $registration;
        }
        $resultEvents = [];
        foreach ($data['results'] ?? [] as $result) {
            $resultEvents[(string) ($result['eventId'] ?? '')] = true;
        }

        $newUsers = [];
        $newRegistrations = [];
        $newResults = [];

        foreach ($data['events'] ?? [] as $index => $event) {
            $id = (string) $event['id'];
            $this->seedFor($id);

            $existing = $registrationsByEvent[$id] ?? [];
            $hasResults = isset($resultEvents[$id]);

            switch ($this->groupOf($event)) {
                case 'finished':
                    $event = $this->closeRegistration($event);
                    $event = $this->ensureGallery($event);
                    [$users, $registrations] = $this->ensureField($event, $existing, 0.75, 1.0);
                    $newUsers = array_merge($newUsers, $users);
                    $newRegistrations = array_merge($newRegistrations, $registrations);
                    if (!$hasResults) {
                        $newResults = array_merge(
                            $newResults,
                            $this->resultsFor($event, array_merge($existing, $registrations))
                        );
                    }
                    break;

                case 'upcoming':
                    // Two in three are taking entries; the rest have not opened yet, which
                    // is a state the registration screens need to be able to show.
                    if ($index % 3 === 2) {
                        $event = $this->notOpenYet($event);
                        break;
                    }
                    $event = $this->openRegistration($event);
                    [$users, $registrations] = $this->ensureField($event, $existing, 0.25, 0.7);
                    $newUsers = array_merge($newUsers, $users);
                    $newRegistrations = array_merge($newRegistrations, $registrations);
                    break;

                default:
                    // Drafts stay empty.
                    break;
            }

            $data['events'][$index] = $event;
        }

        $data['users'] = array_merge($data['users'] ?? [], $newUsers);
        $data['registrations'] = array_merge($data['registrations'] ?? [], $newRegistrations);
        $data['results'] = array_merge($data['results'] ?? [], $newResults);

        return $data;
    }

    /**
     * Results for a start list that already exists — used when an event has its entrants
     * but is missing the finishing times, so no new entrants should be invented.
     *
     * @param array<string, mixed> $event
     * @param array<int, array<string, mixed>> $registrations
     * @return array<int, array<string, mixed>>
     */
    public function resultsForRegistrations(array $event, array $registrations): array
    {
        $this->seedFor((string) $event['id']);

        return $this->resultsFor($event, $registrations);
    }

    /** @param array<string, mixed> $event */
    private function groupOf(array $event): string
    {
        $status = (string) ($event['status'] ?? 'active');

        return match (true) {
            in_array($status, ['past', 'archived'], true) => 'finished',
            in_array($status, ['draft', 'awaiting_approval', 'approved'], true) => 'draft',
            default => 'upcoming',
        };
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function closeRegistration(array $event): array
    {
        $event['registrationStatus'] = 'closed';
        $event['isLive'] = false;

        return $event;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function openRegistration(array $event): array
    {
        $event['registrationStatus'] = 'open';
        if (empty($event['registrationDeadline'])) {
            $event['registrationDeadline'] = $this->daysBefore((string) $event['date'], 7);
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function notOpenYet(array $event): array
    {
        $event['registrationStatus'] = 'none';
        $event['registrationOpenDate'] = $this->daysBefore((string) $event['date'], 90);
        $event['registrationDeadline'] = $this->daysBefore((string) $event['date'], 7);

        return $event;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function ensureGallery(array $event): array
    {
        if (!empty($event['galleryAlbums'])) {
            return $event;
        }

        $slug = (string) ($event['slug'] ?? $event['id']);
        $albums = [];
        foreach (array_slice(self::GALLERY_PLATFORMS, 0, 1 + $this->pick(2)) as $i => $platform) {
            $albums[] = [
                'id' => 'ga-' . $event['id'] . '-' . ($i + 1),
                'name' => $i === 0 ? 'Oficjalna galeria zdjec' : 'Zdjecia uczestnikow',
                'url' => $platform['host'] . $slug,
                'platform' => $platform['platform'],
                'photographer' => self::PHOTOGRAPHERS[$this->pick(count(self::PHOTOGRAPHERS))],
            ];
        }
        $event['galleryAlbums'] = $albums;

        return $event;
    }

    /**
     * Tops each distance up to a plausible share of its limit.
     *
     * @param array<string, mixed> $event
     * @param array<int, array<string, mixed>> $existing
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function ensureField(array $event, array $existing, float $minFill, float $maxFill): array
    {
        $countByDistance = [];
        foreach ($existing as $registration) {
            $key = (string) ($registration['distanceId'] ?? '');
            $countByDistance[$key] = ($countByDistance[$key] ?? 0) + 1;
        }

        $users = [];
        $registrations = [];
        $eventId = (string) $event['id'];
        $eventName = (string) ($event['name'] ?? '');
        $bib = 100 + count($existing);

        foreach ($event['distances'] ?? [] as $distance) {
            $distanceId = (string) ($distance['id'] ?? '');
            $limit = (int) ($distance['limit'] ?? $distance['participantLimit'] ?? 0);
            $ceiling = $limit > 0 ? min($limit, 90) : 45;

            $share = $minFill + ($maxFill - $minFill) * ($this->pick(100) / 100);
            $target = max(6, (int) round($ceiling * $share));
            $missing = $target - ($countByDistance[$distanceId] ?? 0);

            for ($i = 0; $i < $missing; $i++) {
                $user = $this->makeParticipant();
                $users[] = $user;
                $bib++;

                $registrations[] = [
                    'id' => self::shortId('fx-r', $eventId . '|' . $distanceId . '|' . $i),
                    'eventId' => $eventId,
                    'eventName' => $eventName,
                    'userId' => $user['id'],
                    'userName' => $user['name'],
                    'userEmail' => $user['email'],
                    'distanceId' => $distanceId,
                    'distanceName' => (string) ($distance['name'] ?? ''),
                    'category' => $this->category($user),
                    'registeredAt' => $this->registeredAt($event),
                    'status' => 'confirmed',
                    'bibNumber' => (string) $bib,
                    'pricePaid' => $distance['price'] ?? 0,
                    'paymentDetails' => [
                        'startFeePaid' => true,
                        'startFeePaidAt' => $this->registeredAt($event),
                        'startFeeAmount' => $distance['price'] ?? 0,
                        'insurancePaid' => false,
                        'merchandisePayments' => [],
                    ],
                ];
            }
        }

        return [$users, $registrations];
    }

    /**
     * @param array<string, mixed> $event
     * @param array<int, array<string, mixed>> $registrations
     * @return array<int, array<string, mixed>>
     */
    private function resultsFor(array $event, array $registrations): array
    {
        $discipline = (string) ($event['discipline'] ?? 'running');
        [$pace, $spread] = self::PACE[$discipline] ?? self::PACE['running'];

        $lengthById = [];
        foreach ($event['distances'] ?? [] as $distance) {
            $lengthById[(string) ($distance['id'] ?? '')] = (float) ($distance['length'] ?? 10);
        }

        $byDistance = [];
        foreach ($registrations as $registration) {
            if (($registration['status'] ?? '') === 'cancelled') {
                continue;
            }
            $byDistance[(string) ($registration['distanceId'] ?? '')][] = $registration;
        }

        $results = [];
        foreach ($byDistance as $distanceId => $entries) {
            $km = $lengthById[$distanceId] ?? 10;

            $timed = [];
            foreach ($entries as $entry) {
                $noise = ($this->pick(2001) - 1000) / 1000 * $spread;
                $timed[] = ['reg' => $entry, 'seconds' => max(300, (int) round($km * $pace + $noise * sqrt($km)))];
            }
            usort($timed, static fn (array $a, array $b): int => $a['seconds'] <=> $b['seconds']);

            $categoryPositions = [];
            foreach ($timed as $position => $row) {
                $registration = $row['reg'];
                $category = (string) ($registration['category'] ?? 'OPEN');
                $categoryPositions[$category] = ($categoryPositions[$category] ?? 0) + 1;

                $results[] = [
                    'id' => self::shortId('fx-x', (string) $registration['id']),
                    'eventId' => (string) $event['id'],
                    'eventName' => (string) ($event['name'] ?? ''),
                    'distanceId' => $distanceId,
                    'distanceName' => (string) ($registration['distanceName'] ?? ''),
                    'userId' => (string) $registration['userId'],
                    'userName' => (string) $registration['userName'],
                    'bibNumber' => (string) ($registration['bibNumber'] ?? ''),
                    'category' => $category,
                    'time' => $this->formatTime($row['seconds']),
                    'timeInSeconds' => $row['seconds'],
                    'position' => $position + 1,
                    'categoryPosition' => $categoryPositions[$category],
                    'status' => 'finished',
                ];
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function makeParticipant(): array
    {
        $this->participantSeq++;
        $female = $this->pick(2) === 1;
        $first = $female
            ? self::FIRST_NAMES_F[$this->pick(count(self::FIRST_NAMES_F))]
            : self::FIRST_NAMES_M[$this->pick(count(self::FIRST_NAMES_M))];
        $last = self::LAST_NAMES[$this->pick(count(self::LAST_NAMES))];
        // The id has to be unique across every event, not just within this run: a top-up
        // pass builds a fresh filler per event and would otherwise restart the count.
        $id = 'fx-p-' . substr(sha1($this->currentEvent), 0, 12) . '-' . $this->participantSeq;

        return [
            'id' => $id,
            'name' => $first . ' ' . $last,
            'email' => strtolower($first . '.' . $last) . '.' . substr(sha1($id), 0, 8) . '@demo.test',
            'role' => 'participant',
            'phone' => '5' . str_pad((string) $this->pick(100000000), 8, '0', STR_PAD_LEFT),
            'birthYear' => 1970 + $this->pick(36),
            'gender' => $female ? 'K' : 'M',
            'club' => $this->pick(3) === 0 ? null : self::CLUBS[$this->pick(count(self::CLUBS))],
        ];
    }

    /** @param array<string, mixed> $user */
    private function category(array $user): string
    {
        $age = (int) $this->today->format('Y') - (int) ($user['birthYear'] ?? 1990);
        $band = match (true) {
            $age < 25 => '18',
            $age < 35 => '30',
            $age < 45 => '40',
            $age < 55 => '50',
            default => '60',
        };

        return ($user['gender'] ?? 'M') . $band;
    }

    /** @param array<string, mixed> $event */
    private function registeredAt(array $event): string
    {
        $deadline = (string) ($event['registrationDeadline'] ?? $event['date'] ?? '');
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', substr($deadline, 0, 10));
        if ($base === false) {
            $base = $this->today;
        }
        $daysBefore = 10 + $this->pick(80);
        $moment = $base->modify('-' . $daysBefore . ' days');
        if ($moment > $this->today) {
            $moment = $this->today->modify('-' . (1 + $this->pick(20)) . ' days');
        }

        return $moment->format('Y-m-d') . 'T' . str_pad((string) (7 + $this->pick(13)), 2, '0', STR_PAD_LEFT) . ':30:00+02:00';
    }

    private function daysBefore(string $date, int $days): string
    {
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        return $base === false ? $date : $base->modify('-' . $days . ' days')->format('Y-m-d');
    }

    private function formatTime(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Ids have to fit the VARCHAR(36) primary keys; anything longer is silently truncated
     * by the database and starts colliding. Hashing keeps them short and deterministic.
     */
    private static function shortId(string $prefix, string $seed): string
    {
        return $prefix . '-' . substr(sha1($seed), 0, 24);
    }

    /** Seeding per event keeps a re-run identical without making every event identical. */
    private function seedFor(string $eventId): void
    {
        $this->currentEvent = $eventId;
        mt_srand(crc32($eventId));
    }

    private function pick(int $bound): int
    {
        return $bound <= 1 ? 0 : mt_rand(0, $bound - 1);
    }
}
