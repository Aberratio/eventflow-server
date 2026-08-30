<?php
/**
 * Generator danych testowych dla wydarzeń sportowych.
 *
 * Użycie:
 * Zwykle wolane przez bin/seed.php, ktore zapisuje wynik do
 * database/seeders/.generated/ i nie trzyma go w repo.
 *
 *   php generate_events.php                     # generuje .generated/002_test_events.sql
 *   php generate_events.php --out=custom.sql    # niestandardowy plik wyjściowy
 *   php generate_events.php --seed=7            # inny zestaw losowych danych
 *   php generate_events.php --dry-run           # wypisuje SQL na stdout
 *
 * Hasło dla wszystkich wygenerowanych kont: demo1234
 * Organizer: test-organizer (test.organizer@zmierzymyczas.pl)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Konfiguracja
// ---------------------------------------------------------------------------

const ORGANIZER_ID   = 'test-organizer';
const ORGANIZER_NAME = 'Test Organizer';
const PASSWORD_HASH  = '$2y$10$m6sRohb7PEr9eIwzEm5g0OeOprsHvENzHtHQbBoVkGSjh62gm7oYK'; // demo1234, same hash as database/seeders/001_demo.sql

const PAST_EVENT_ID      = 'e-test-bieg-lesny-2025';
const CURRENT_EVENT_ID   = 'e-test-triathlon-2026';
const UPCOMING_EVENT_ID  = 'e-test-maraton-2027';

const PAST_EVENT_PARTICIPANT_COUNT = 120;

/** Fixed RNG seed keeps generated fixtures reproducible; override with --seed=N. */
const DEFAULT_RNG_SEED = 42;

// ---------------------------------------------------------------------------
// Dane pomocnicze
// ---------------------------------------------------------------------------

$MALE_FIRST_NAMES = ['Adam','Bartosz','Cezary','Damian','Emil','Filip','Grzegorz','Hubert',
    'Igor','Jakub','Kamil','Leszek','Marek','Norbert','Oskar','Pawel','Rafal','Sebastian',
    'Tomasz','Urban','Wiktor','Xawier','Zenon','Artur','Bogdan','Dawid','Eryk','Konrad'];

$FEMALE_FIRST_NAMES = ['Anna','Beata','Celina','Dorota','Ewa','Fatima','Gabriela','Hanna',
    'Irena','Joanna','Karolina','Lidia','Marta','Natalia','Olga','Patrycja','Roma','Sandra',
    'Teresa','Urszula','Weronika','Zuzanna','Agnieszka','Barbara','Danuta','Elzbieta','Kinga'];

$LAST_NAMES = ['Kowalski','Nowak','Wisniewski','Wojcik','Kaminski','Lewandowski','Zielinski',
    'Szymanski','Wozniak','Dabrowska','Kozlowski','Jankowski','Mazur','Wojciechowski','Kwiatkowski',
    'Krawczyk','Piotrowska','Grabowski','Nowakowski','Pawlowski','Michalska','Nowacki','Adamczyk',
    'Dudek','Zajac','Wieczorek','Jablonski','Krol','Majewski','Olszewski','Jaworski','Wrobel',
    'Stepien','Malinowski','Ostrowski','Duda','Kubiak','Kazmierczak','Zawadzki','Pietrzak'];

$CLUBS = ['Biegacze Warszawy','Run Club Lodz','Tri Team Gdansk','Rowerowy Krakow',
    'Athletic Poznan','Sportowi Wroclaw','Maraton Lublin','UKS Biegun','Klub Biegacza Silesia',
    'Trójmiejski Klub Biegacza','Maratończycy Małopolski','Biegiem przez Polskę',
    'Krakowska Szkoła Biegania','Varsovia Runners','Poznań Running Team','Łódzki Maraton Klub'];

$CITIES = ['Warszawa','Krakow','Lodz','Wroclaw','Poznan','Gdansk','Szczecin','Bydgoszcz',
    'Lublin','Katowice','Bialystok','Gdynia','Czestochowa','Radom','Sosnowiec','Torun'];

// ---------------------------------------------------------------------------
// Narzędzia generatora
// ---------------------------------------------------------------------------

function uuid(string $prefix = ''): string
{
    $hex = bin2hex(random_bytes(16));
    $uuid = sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8), substr($hex, 8, 4),
        '4' . substr($hex, 13, 3),
        dechex(0x80 | (hexdec(substr($hex, 16, 2)) & 0x3f)) . substr($hex, 18, 2),
        substr($hex, 20, 12)
    );
    return $prefix ? "{$prefix}-{$uuid}" : $uuid;
}

function sq(mixed $v): string
{
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    if (is_bool($v)) return $v ? '1' : '0';
    return "'" . str_replace("'", "''", (string)$v) . "'";
}

function js(mixed $v): string
{
    return sq(json_encode($v, JSON_UNESCAPED_UNICODE));
}

/**
 * Generuje czas biegu w sekundach na podstawie dystansu i tempa.
 * Tempo w sek/km + losowy szum.
 */
function generateRunTime(float $distanceKm, float $paceSecPerKm, float $stddevSec = 60): int
{
    $base = $distanceKm * $paceSecPerKm;
    // Box-Muller dla przybliżonego rozkładu normalnego
    // lcg_value() has its own generator, seeded from the clock and immune to mt_srand,
    // which made every regenerated fixture set produce different finish times.
    $u1 = mt_rand(1, mt_getrandmax()) / (mt_getrandmax() + 1);
    $u2 = mt_rand(1, mt_getrandmax()) / (mt_getrandmax() + 1);
    $noise = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2) * $stddevSec;
    return max(60, (int)round($base + $noise));
}

function secondsToTime(int $s): string
{
    return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

function formatRegisteredAt(string $baseDate, int $offsetDays = 0, int $offsetHours = 0): string
{
    $ts = strtotime($baseDate) + $offsetDays * 86400 + $offsetHours * 3600;
    return date('Y-m-d\TH:i:s+02:00', $ts);
}

// ---------------------------------------------------------------------------
// Generowanie uczestników
// ---------------------------------------------------------------------------

function generateParticipants(
    int $count,
    string $prefix,
    array $maleNames,
    array $femaleNames,
    array $lastNames,
    array $clubs,
    array $cities
): array {
    $participants = [];
    for ($i = 1; $i <= $count; $i++) {
        $gender = ($i % 3 === 0) ? 'K' : 'M';
        $firstName = $gender === 'K'
            ? $femaleNames[array_rand($femaleNames)]
            : $maleNames[array_rand($maleNames)];
        $lastName = $lastNames[array_rand($lastNames)];
        // Małe litery + cyfra żeby e-maile były unikalne
        $emailBase = strtolower(
            iconv('UTF-8', 'ASCII//TRANSLIT', $firstName) . '.' .
            iconv('UTF-8', 'ASCII//TRANSLIT', $lastName)
        );
        $birthYear = rand(1975, 2005);

        $participants[] = [
            'id'          => "{$prefix}-p{$i}",
            'name'        => "{$firstName} {$lastName}",
            'email'       => "{$emailBase}{$i}@demo.test",
            'gender'      => $gender,
            'birth_year'  => $birthYear,
            'club'        => ($i % 4 === 0) ? null : $clubs[array_rand($clubs)],
            'phone'       => '5' . str_pad((string)rand(10000000, 99999999), 8, '0'),
        ];
    }
    return $participants;
}

// ---------------------------------------------------------------------------
// Bloki SQL
// ---------------------------------------------------------------------------

function renderUsers(array $participants): string
{
    $lines = [];
    foreach ($participants as $p) {
        $lines[] = sprintf(
            "(%s,%s,%s,%s,'participant',%s,%s,%s,%s,NULL,'active',0)",
            sq($p['id']), sq($p['name']), sq($p['email']),
            sq(PASSWORD_HASH),
            sq($p['phone'] ?? null),
            sq($p['birth_year']),
            sq($p['gender']),
            sq($p['club'] ?? null)
        );
    }
    return "INSERT INTO users (id,name,email,password_hash,role,phone,birth_year,gender,club,company_name,status,event_limit) VALUES\n"
        . implode(",\n", $lines) . "\nON DUPLICATE KEY UPDATE name=VALUES(name),email=VALUES(email);\n";
}

// ---------------------------------------------------------------------------
// WYDARZENIE 1: Przeszłe — Leśny Bieg Testowy 2025
// ---------------------------------------------------------------------------

function buildPastEvent(
    array $participants,
    array $maleNames,
    array $femaleNames,
    array $lastNames
): string {
    $eventId   = PAST_EVENT_ID;
    $eventName = 'Leśny Bieg Testowy 2025';
    $eventDate = '2025-09-13';
    $orgId     = ORGANIZER_ID;
    $orgName   = ORGANIZER_NAME;

    $distances = [
        ['id' => "{$eventId}-d5k",  'name' => '5 km',       'length' => 5.0,
         'pace_mean' => 330, 'pace_std' => 45, 'price' => 49.00, 'limit' => 200, 'sort' => 0,
         'categories' => ['OPEN','M30','M40','M50','K30','K40','K50']],
        ['id' => "{$eventId}-d10k", 'name' => '10 km',      'length' => 10.0,
         'pace_mean' => 350, 'pace_std' => 50, 'price' => 69.00, 'limit' => 300, 'sort' => 1,
         'categories' => ['OPEN','M30','M40','M50','K30','K40','K50']],
        ['id' => "{$eventId}-d21k", 'name' => 'Półmaraton', 'length' => 21.097,
         'pace_mean' => 380, 'pace_std' => 60, 'price' => 99.00, 'limit' => 250, 'sort' => 2,
         'categories' => ['OPEN','M30','M40','M50','K30','K40','K50']],
    ];

    // Rozdziel uczestników między dystanse: ~30% 5km, ~40% 10km, ~30% 21km
    $count = count($participants);
    $d5k_count  = (int)round($count * 0.30);
    $d10k_count = (int)round($count * 0.40);
    $d21k_count = $count - $d5k_count - $d10k_count;

    $assigned = [];
    foreach ($participants as $i => $p) {
        if ($i < $d5k_count) $distIdx = 0;
        elseif ($i < $d5k_count + $d10k_count) $distIdx = 1;
        else $distIdx = 2;
        $assigned[] = ['participant' => $p, 'distIdx' => $distIdx];
    }

    $sql = "";

    // Event
    $sql .= "INSERT INTO events (id,name,slug,discipline,date,location,city,description,organizer_id,organizer_name,organizer_contact,publication_status,registration_deadline,registration_status,featured,is_live,is_virtual,payment_config,rules_html,require_final_acceptance,is_private,registration_form_config,display_options,created_by) VALUES (\n";
    $sql .= sprintf(
        "  %s,%s,'lesny-bieg-testowy-2025','running',%s,'Las Kabacki','Warszawa','Rekreacyjny bieg w lesie kabackim z trzema dystansami.',%s,%s,%s,'finished',%s,'closed',0,0,0,%s,'<p>Regulamin.</p>',0,0,%s,%s,%s\n",
        sq($eventId), sq($eventName),
        sq($eventDate),
        sq($orgId), sq($orgName),
        js(['email' => 'test.organizer@zmierzymyczas.pl', 'phone' => '510000002']),
        sq('2025-09-06'),
        js(['type' => 'organizer_bank', 'bankAccountNumber' => '11 2222 3333 4444 5555 6666 7777',
            'bankAccountHolder' => 'Test Organizer Sp. z o.o.', 'transferTitle' => 'Lesny Bieg 2025']),
        js(['collectPhone' => true, 'collectClub' => true, 'collectBirthDate' => true,
            'collectTeam' => false, 'collectInsurance' => false, 'collectMerchandise' => false,
            'customFields' => []]),
        js(['defaultSort' => 'time', 'uppercaseNames' => false, 'hideUnpaid' => false]),
        sq($orgId)
    );
    $sql .= ");\n\n";

    // Dystanse
    $distanceRows = [];
    foreach ($distances as $d) {
        $distanceRows[] = sprintf(
            "(%s,%s,%s,%.3f,'km',%s,%s,0,%s,%s,0)",
            sq($d['id']), sq($eventId), sq($d['name']), $d['length'],
            js($d['categories']),
            $d['limit'],
            $d['price'],
            js([]),
            $d['sort']
        );
    }
    $sql .= "INSERT INTO event_distances (id,event_id,name,length,unit,categories,participant_limit,registered_count,price,price_tiers,sort_order) VALUES\n";
    $sql .= implode(",\n", $distanceRows) . ";\n\n";

    // Rejestracje i wyniki
    $registrationRows = [];
    $paymentRows      = [];
    $resultRows       = [];
    $settlementRows   = [];

    // Zbieraj wyniki per dystans dla rankingu
    $resultsByDist = [0 => [], 1 => [], 2 => []];

    foreach ($assigned as $idx => $item) {
        $p       = $item['participant'];
        $distIdx = $item['distIdx'];
        $dist    = $distances[$distIdx];

        $regId  = "r-past-{$p['id']}";
        $bibNum = (string)(100 + $idx + 1);
        $cat    = ageCategory($p['birth_year'], $p['gender']);
        $regAt  = formatRegisteredAt('2025-08-01', rand(0, 35), rand(8, 20));

        $timeS = generateRunTime($dist['length'], $dist['pace_mean'], $dist['pace_std']);

        $registrationRows[] = sprintf(
            "(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'confirmed',%s)",
            sq($regId), sq($eventId), sq($eventName),
            sq($p['id']), sq($p['name']), sq($p['email']),
            sq($dist['id']), sq($dist['name']),
            sq($cat),
            sq($regAt),
            sq($bibNum)
        );

        // Rozliczenie (E0.3): "Oplata startowa" line item replaces registrations.price_paid.
        $settlementRows[] = sprintf(
            "(%s,%s,'Oplata startowa','distance_price',%.2f,%s,0)",
            sq(uuid('settle-')), sq($regId), $dist['price'], sq($regAt)
        );

        $paidAt = date('Y-m-d\TH:i:s+02:00', strtotime($regAt) + rand(300, 3600));
        $paymentRows[] = sprintf(
            "(%s,1,%s,%.2f,0,'[]')",
            sq($regId), sq($paidAt), $dist['price']
        );

        $resultsByDist[$distIdx][] = [
            'id'      => "res-past-{$p['id']}",
            'p'       => $p,
            'dist'    => $dist,
            'bib'     => $bibNum,
            'cat'     => $cat,
            'time_s'  => $timeS,
        ];
    }

    // Posortuj wyniki i przypisz pozycje
    foreach ($resultsByDist as $distIdx => $rows) {
        usort($rows, fn($a, $b) => $a['time_s'] <=> $b['time_s']);

        // Pozycje per kategoria
        $catPositions = [];
        foreach ($rows as $pos => $row) {
            $cat = $row['cat'];
            $catPositions[$cat] = ($catPositions[$cat] ?? 0) + 1;

            $resultRows[] = sprintf(
                "(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,'finished',%s,%d)",
                sq($row['id']),
                sq($eventId), sq($eventName),
                sq($row['dist']['id']), sq($row['dist']['name']),
                sq($row['p']['id']), sq($row['p']['name']),
                sq($row['bib']),
                sq($row['cat']),
                sq(secondsToTime($row['time_s'])),
                $row['time_s'],
                $pos + 1,
                $catPositions[$cat],
                sq($row['p']['gender']),
                $row['p']['birth_year']
            );
        }
    }

    $sql .= "INSERT INTO registrations (id,event_id,event_name,user_id,user_name,user_email,distance_id,distance_name,category,registered_at,status,bib_number) VALUES\n";
    $sql .= implode(",\n", $registrationRows) . ";\n\n";

    $sql .= "INSERT INTO registration_settlement_items (id,registration_id,name,basis,amount,frozen_at,sort_order) VALUES\n";
    $sql .= implode(",\n", $settlementRows) . ";\n\n";

    $sql .= "INSERT INTO registration_payment_details (registration_id,start_fee_paid,start_fee_paid_at,start_fee_amount,insurance_paid,merchandise_payments) VALUES\n";
    $sql .= implode(",\n", $paymentRows) . ";\n\n";

    $sql .= "INSERT INTO results (id,event_id,event_name,distance_id,distance_name,user_id,user_name,bib_number,category,time,time_in_seconds,position,category_position,status,gender,birth_year) VALUES\n";
    $sql .= implode(",\n", $resultRows) . ";\n\n";

    // Zaktualizuj registered_count
    foreach ($distances as $d) {
        $cnt = count(array_filter($assigned, fn($a) => $distances[$a['distIdx']]['id'] === $d['id']));
        $sql .= "UPDATE event_distances SET registered_count={$cnt} WHERE id=" . sq($d['id']) . ";\n";
    }
    $sql .= "\n";

    return $sql;
}

// ---------------------------------------------------------------------------
// WYDARZENIE 2: Bieżące — Tri Kids Mazury 2026 (zapisy zamknięte 18.06)
// ---------------------------------------------------------------------------

function buildCurrentEvent(): array
{
    $eventId   = CURRENT_EVENT_ID;
    $eventName = 'Tri Kids Mazury 2026';
    $eventDate = '2026-06-25';
    $orgId     = ORGANIZER_ID;
    $orgName   = ORGANIZER_NAME;

    // Uczestnicy dzieci i młodzieży — 40 osób łącznie
    $participants = [
        // Dzieci (urodzeni 2014-2018, bieg 200m + rower 1km + basen 50m)
        ['id' => 'u-kids-1',  'name' => 'Zofia Kowalska',    'email' => 'zofia.kowalska1@demo.test',    'gender' => 'K', 'birth_year' => 2016, 'club' => 'UKS Biegun'],
        ['id' => 'u-kids-2',  'name' => 'Maja Nowak',         'email' => 'maja.nowak2@demo.test',         'gender' => 'K', 'birth_year' => 2015, 'club' => null],
        ['id' => 'u-kids-3',  'name' => 'Lena Wisniewski',   'email' => 'lena.wisniewski3@demo.test',   'gender' => 'K', 'birth_year' => 2017, 'club' => 'Klub Biegacza Silesia'],
        ['id' => 'u-kids-4',  'name' => 'Julia Kaminska',    'email' => 'julia.kaminska4@demo.test',    'gender' => 'K', 'birth_year' => 2014, 'club' => null],
        ['id' => 'u-kids-5',  'name' => 'Natalia Wojcik',    'email' => 'natalia.wojcik5@demo.test',    'gender' => 'K', 'birth_year' => 2016, 'club' => 'Biegacze Warszawy'],
        ['id' => 'u-kids-6',  'name' => 'Aleksandra Dabrowska', 'email' => 'aleksandra.dabrowska6@demo.test', 'gender' => 'K', 'birth_year' => 2018, 'club' => null],
        ['id' => 'u-kids-7',  'name' => 'Mia Grabowska',     'email' => 'mia.grabowska7@demo.test',     'gender' => 'K', 'birth_year' => 2017, 'club' => 'UKS Biegun'],
        ['id' => 'u-kids-8',  'name' => 'Oliwia Jablonska',  'email' => 'oliwia.jablonska8@demo.test',  'gender' => 'K', 'birth_year' => 2015, 'club' => null],
        ['id' => 'u-kids-9',  'name' => 'Jakub Lewandowski', 'email' => 'jakub.lewandowski9@demo.test', 'gender' => 'M', 'birth_year' => 2016, 'club' => 'Biegacze Warszawy'],
        ['id' => 'u-kids-10', 'name' => 'Bartosz Mazur',     'email' => 'bartosz.mazur10@demo.test',    'gender' => 'M', 'birth_year' => 2014, 'club' => null],
        ['id' => 'u-kids-11', 'name' => 'Szymon Zielinski',  'email' => 'szymon.zielinski11@demo.test', 'gender' => 'M', 'birth_year' => 2017, 'club' => 'Klub Biegacza Silesia'],
        ['id' => 'u-kids-12', 'name' => 'Michal Krol',       'email' => 'michal.krol12@demo.test',      'gender' => 'M', 'birth_year' => 2015, 'club' => null],
        ['id' => 'u-kids-13', 'name' => 'Kacper Wojciechowski', 'email' => 'kacper.wojciechowski13@demo.test', 'gender' => 'M', 'birth_year' => 2018, 'club' => 'UKS Biegun'],
        ['id' => 'u-kids-14', 'name' => 'Filip Pawlowski',   'email' => 'filip.pawlowski14@demo.test',  'gender' => 'M', 'birth_year' => 2016, 'club' => null],
        ['id' => 'u-kids-15', 'name' => 'Adam Kwiatkowski',  'email' => 'adam.kwiatkowski15@demo.test', 'gender' => 'M', 'birth_year' => 2014, 'club' => 'Athletic Poznan'],
        ['id' => 'u-kids-16', 'name' => 'Tomek Stepien',     'email' => 'tomek.stepien16@demo.test',    'gender' => 'M', 'birth_year' => 2017, 'club' => null],
        // Młodzież (urodzeni 2009-2013, bieg 400m + rower 4km + basen 100m)
        ['id' => 'u-youth-1', 'name' => 'Wiktoria Malinowska', 'email' => 'wiktoria.malinowska17@demo.test', 'gender' => 'K', 'birth_year' => 2011, 'club' => 'Tri Team Gdansk'],
        ['id' => 'u-youth-2', 'name' => 'Karolina Krawczyk',  'email' => 'karolina.krawczyk18@demo.test',  'gender' => 'K', 'birth_year' => 2010, 'club' => null],
        ['id' => 'u-youth-3', 'name' => 'Iga Piotrowska',     'email' => 'iga.piotrowska19@demo.test',     'gender' => 'K', 'birth_year' => 2012, 'club' => 'Sportowi Wroclaw'],
        ['id' => 'u-youth-4', 'name' => 'Sara Dudek',         'email' => 'sara.dudek20@demo.test',         'gender' => 'K', 'birth_year' => 2009, 'club' => 'Athletic Poznan'],
        ['id' => 'u-youth-5', 'name' => 'Natasza Zajac',      'email' => 'natasza.zajac21@demo.test',      'gender' => 'K', 'birth_year' => 2013, 'club' => null],
        ['id' => 'u-youth-6', 'name' => 'Paulina Wieczorek',  'email' => 'paulina.wieczorek22@demo.test',  'gender' => 'K', 'birth_year' => 2011, 'club' => 'Tri Team Gdansk'],
        ['id' => 'u-youth-7', 'name' => 'Marta Kubiak',       'email' => 'marta.kubiak23@demo.test',       'gender' => 'K', 'birth_year' => 2010, 'club' => null],
        ['id' => 'u-youth-8', 'name' => 'Zosia Kazmierczak',  'email' => 'zosia.kazmierczak24@demo.test',  'gender' => 'K', 'birth_year' => 2012, 'club' => 'UKS Biegun'],
        ['id' => 'u-youth-9', 'name' => 'Piotr Ostrowski',    'email' => 'piotr.ostrowski25@demo.test',    'gender' => 'M', 'birth_year' => 2011, 'club' => 'Tri Team Gdansk'],
        ['id' => 'u-youth-10','name' => 'Kamil Duda',         'email' => 'kamil.duda26@demo.test',         'gender' => 'M', 'birth_year' => 2009, 'club' => null],
        ['id' => 'u-youth-11','name' => 'Mateusz Kubiak',     'email' => 'mateusz.kubiak27@demo.test',     'gender' => 'M', 'birth_year' => 2013, 'club' => 'Sportowi Wroclaw'],
        ['id' => 'u-youth-12','name' => 'Adrian Pietrzak',    'email' => 'adrian.pietrzak28@demo.test',    'gender' => 'M', 'birth_year' => 2010, 'club' => null],
        ['id' => 'u-youth-13','name' => 'Krzysztof Zawadzki', 'email' => 'krzysztof.zawadzki29@demo.test', 'gender' => 'M', 'birth_year' => 2012, 'club' => 'Athletic Poznan'],
        ['id' => 'u-youth-14','name' => 'Dawid Nowakowski',   'email' => 'dawid.nowakowski30@demo.test',   'gender' => 'M', 'birth_year' => 2011, 'club' => 'UKS Biegun'],
        ['id' => 'u-youth-15','name' => 'Lukasz Michalski',   'email' => 'lukasz.michalski31@demo.test',   'gender' => 'M', 'birth_year' => 2009, 'club' => null],
        ['id' => 'u-youth-16','name' => 'Pawel Adamczyk',     'email' => 'pawel.adamczyk32@demo.test',     'gender' => 'M', 'birth_year' => 2013, 'club' => 'Tri Team Gdansk'],
        ['id' => 'u-youth-17','name' => 'Radek Jablonski',    'email' => 'radek.jablonski33@demo.test',    'gender' => 'M', 'birth_year' => 2010, 'club' => null],
        ['id' => 'u-youth-18','name' => 'Konrad Majewski',    'email' => 'konrad.majewski34@demo.test',    'gender' => 'M', 'birth_year' => 2012, 'club' => 'Sportowi Wroclaw'],
        ['id' => 'u-youth-19','name' => 'Sebastian Olszewski','email' => 'sebastian.olszewski35@demo.test', 'gender' => 'M', 'birth_year' => 2011, 'club' => null],
        ['id' => 'u-youth-20','name' => 'Rafal Jaworski',     'email' => 'rafal.jaworski36@demo.test',     'gender' => 'M', 'birth_year' => 2013, 'club' => 'Athletic Poznan'],
        ['id' => 'u-youth-21','name' => 'Tomasz Wrobel',      'email' => 'tomasz.wrobel37@demo.test',      'gender' => 'M', 'birth_year' => 2009, 'club' => null],
        ['id' => 'u-youth-22','name' => 'Grzegorz Krawczyk',  'email' => 'grzegorz.krawczyk38@demo.test',  'gender' => 'M', 'birth_year' => 2012, 'club' => 'Tri Team Gdansk'],
        ['id' => 'u-youth-23','name' => 'Hubert Stepien',     'email' => 'hubert.stepien39@demo.test',     'gender' => 'M', 'birth_year' => 2010, 'club' => null],
        ['id' => 'u-youth-24','name' => 'Norbert Malinowski', 'email' => 'norbert.malinowski40@demo.test', 'gender' => 'M', 'birth_year' => 2011, 'club' => 'UKS Biegun'],
    ];

    $distances = [
        'kids'  => ['id' => "{$eventId}-dzieci",    'name' => 'Dzieci (200m/1km/50m)',   'length' => 1.25,  'price' => 30.00, 'limit' => 100, 'sort' => 0,
                    'categories' => ['Dziewczynki', 'Chlopcy']],
        'youth' => ['id' => "{$eventId}-mlodziez",  'name' => 'Młodzież (400m/4km/100m)','length' => 4.50,  'price' => 40.00, 'limit' => 100, 'sort' => 1,
                    'categories' => ['Dziewczeta', 'Chlopcy']],
    ];

    $sql = "";

    // Event
    $sql .= "INSERT INTO events (id,name,slug,discipline,date,location,city,description,organizer_id,organizer_name,organizer_contact,publication_status,registration_deadline,registration_status,featured,is_live,is_virtual,payment_config,rules_html,require_final_acceptance,is_private,registration_form_config,display_options,created_by) VALUES (\n";
    $sql .= sprintf(
        "  %s,%s,'tri-kids-mazury-2026','triathlon',%s,'Centrum Triathlonu Mikołajki','Mikolajki','Triathlon dla dzieci i mlodziezy nad jeziorem Sniardwy.',%s,%s,%s,'published',%s,'closed',1,0,0,%s,'<p>Regulamin zawodow mlodziezowych.</p>',0,0,%s,%s,%s\n",
        sq($eventId), sq($eventName),
        sq($eventDate),
        sq($orgId), sq($orgName),
        js(['email' => 'test.organizer@zmierzymyczas.pl', 'phone' => '510000002']),
        sq('2026-06-18'),
        js(['type' => 'organizer_bank', 'bankAccountNumber' => '22 3333 4444 5555 6666 7777 8888',
            'bankAccountHolder' => 'Test Organizer Sp. z o.o.', 'transferTitle' => 'Tri Kids Mazury 2026']),
        js(['collectPhone' => true, 'collectClub' => true, 'collectBirthDate' => true,
            'collectTeam' => false, 'collectInsurance' => true, 'collectMerchandise' => false,
            'customFields' => []]),
        js(['defaultSort' => 'bib', 'uppercaseNames' => false, 'hideUnpaid' => false]),
        sq($orgId)
    );
    $sql .= ");\n\n";

    // Dystanse
    $distRows = [];
    foreach ($distances as $dist) {
        $distRows[] = sprintf(
            "(%s,%s,%s,%.3f,'km',%s,%s,0,%.2f,%s,%d)",
            sq($dist['id']), sq($eventId), sq($dist['name']), $dist['length'],
            js($dist['categories']),
            $dist['limit'],
            $dist['price'],
            js([]),
            $dist['sort']
        );
    }
    $sql .= "INSERT INTO event_distances (id,event_id,name,length,unit,categories,participant_limit,registered_count,price,price_tiers,sort_order) VALUES\n";
    $sql .= implode(",\n", $distRows) . ";\n\n";

    // Rejestracje — część opłacona, część nie
    $regRows = []; $payRows = []; $settleRows = [];
    $kidsParticipants  = array_filter($participants, fn($p) => str_starts_with($p['id'], 'u-kids-'));
    $youthParticipants = array_filter($participants, fn($p) => str_starts_with($p['id'], 'u-youth-'));

    $bibCounter = 1;
    foreach ([['group' => $kidsParticipants, 'dist' => $distances['kids']],
               ['group' => $youthParticipants, 'dist' => $distances['youth']]] as $batch) {
        foreach ($batch['group'] as $i => $p) {
            $regId  = "r-curr-{$p['id']}";
            $bibNum = (string)(200 + $bibCounter++);
            $cat    = $p['gender'] === 'K'
                ? ($batch['dist']['id'] === "{$eventId}-dzieci" ? 'Dziewczynki' : 'Dziewczeta')
                : 'Chlopcy';
            $regAt  = formatRegisteredAt('2026-06-01', rand(0, 17), rand(9, 18));
            // ~65% opłaconych
            $paid = ($bibCounter % 3 !== 0);
            $status = $paid ? 'confirmed' : 'pending';

            $regRows[] = sprintf(
                "(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                sq($regId), sq($eventId), sq($eventName),
                sq($p['id']), sq($p['name']), sq($p['email']),
                sq($batch['dist']['id']), sq($batch['dist']['name']),
                sq($cat),
                sq($regAt),
                sq($status),
                sq($bibNum)
            );

            // Rozliczenie (E0.3): replaces registrations.price_paid.
            $settleRows[] = sprintf(
                "(%s,%s,'Oplata startowa','distance_price',%.2f,%s,0)",
                sq(uuid('settle-')), sq($regId), $batch['dist']['price'], sq($regAt)
            );

            if ($paid) {
                $paidAt = date('Y-m-d\TH:i:s+02:00', strtotime($regAt) + rand(300, 7200));
                $payRows[] = sprintf(
                    "(%s,1,%s,%.2f,0,'[]')",
                    sq($regId), sq($paidAt), $batch['dist']['price']
                );
            } else {
                $payRows[] = sprintf(
                    "(%s,0,NULL,%.2f,0,'[]')",
                    sq($regId), $batch['dist']['price']
                );
            }
        }
    }

    $sql .= "INSERT INTO registrations (id,event_id,event_name,user_id,user_name,user_email,distance_id,distance_name,category,registered_at,status,bib_number) VALUES\n";
    $sql .= implode(",\n", $regRows) . ";\n\n";

    $sql .= "INSERT INTO registration_settlement_items (id,registration_id,name,basis,amount,frozen_at,sort_order) VALUES\n";
    $sql .= implode(",\n", $settleRows) . ";\n\n";

    $sql .= "INSERT INTO registration_payment_details (registration_id,start_fee_paid,start_fee_paid_at,start_fee_amount,insurance_paid,merchandise_payments) VALUES\n";
    $sql .= implode(",\n", $payRows) . ";\n\n";

    // Zaktualizuj counted
    $sql .= sprintf("UPDATE event_distances SET registered_count=%d WHERE id=%s;\n", count($kidsParticipants),  sq($distances['kids']['id']));
    $sql .= sprintf("UPDATE event_distances SET registered_count=%d WHERE id=%s;\n", count($youthParticipants), sq($distances['youth']['id']));
    $sql .= "\n";

    return ['sql' => $sql, 'participants' => $participants];
}

// ---------------------------------------------------------------------------
// WYDARZENIE 3: Nadchodzące — Grand Prix Podlasia 2027
// ---------------------------------------------------------------------------

function buildUpcomingEvent(): string
{
    $eventId   = UPCOMING_EVENT_ID;
    $eventName = 'Grand Prix Podlasia 2027';
    $eventDate = '2027-05-15';
    $orgId     = ORGANIZER_ID;
    $orgName   = ORGANIZER_NAME;

    $sql = "INSERT INTO events (id,name,slug,discipline,date,location,city,description,organizer_id,organizer_name,organizer_contact,publication_status,registration_deadline,registration_open_date,registration_status,featured,is_live,is_virtual,payment_config,rules_html,require_final_acceptance,is_private,registration_form_config,display_options,created_by) VALUES (\n";
    $sql .= sprintf(
        "  %s,%s,'grand-prix-podlasia-2027','running',%s,'Stadion Miejski','Bialystok','Bieg uliczny przez Bialystok – trasa asfaltowa, płaski profil, limit czasu 4h.',%s,%s,%s,'published',%s,%s,'open',1,0,0,%s,'<p>Regulamin biegu.</p>',1,0,%s,%s,%s\n",
        sq($eventId), sq($eventName),
        sq($eventDate),
        sq($orgId), sq($orgName),
        js(['email' => 'test.organizer@zmierzymyczas.pl', 'phone' => '510000002', 'website' => 'https://gpp2027.pl']),
        sq('2027-05-08'),
        sq('2026-10-01'),
        js(['type' => 'admin_gateway']),
        js(['collectPhone' => true, 'collectClub' => true, 'collectBirthDate' => true,
            'collectTeam' => true, 'collectInsurance' => true, 'collectMerchandise' => true,
            'customFields' => [['id' => 'ice', 'label' => 'Telefon ICE', 'type' => 'text', 'required' => true]]]),
        js(['defaultSort' => 'bib', 'uppercaseNames' => false, 'hideUnpaid' => true]),
        sq($orgId)
    );
    $sql .= ");\n\n";

    $distances = [
        ['id' => "{$eventId}-d10k", 'name' => '10 km',      'length' => 10.0,   'price' => 79.00,  'limit' => 2000,
         'categories' => ['OPEN','M18','M30','M40','M50','K18','K30','K40','K50'], 'sort' => 0,
         'tiers' => [['id' => 'pt1', 'name' => 'Early bird', 'discountType' => 'fixed', 'discountValue' => 20, 'endDate' => '2026-12-31']]],
        ['id' => "{$eventId}-d21k", 'name' => 'Półmaraton', 'length' => 21.097, 'price' => 119.00, 'limit' => 3000,
         'categories' => ['OPEN','M18','M30','M40','M50','K18','K30','K40','K50'], 'sort' => 1,
         'tiers' => [['id' => 'pt2', 'name' => 'Early bird', 'discountType' => 'fixed', 'discountValue' => 30, 'endDate' => '2026-12-31']]],
        ['id' => "{$eventId}-d42k", 'name' => 'Maraton',    'length' => 42.195, 'price' => 159.00, 'limit' => 1500,
         'categories' => ['OPEN','M18','M30','M40','M50','K18','K30','K40','K50'], 'sort' => 2,
         'tiers' => [['id' => 'pt3', 'name' => 'Early bird', 'discountType' => 'fixed', 'discountValue' => 40, 'endDate' => '2026-12-31']]],
    ];

    $distRows = [];
    foreach ($distances as $d) {
        $distRows[] = sprintf(
            "(%s,%s,%s,%.3f,'km',%s,%s,0,%.2f,%s,%d)",
            sq($d['id']), sq($eventId), sq($d['name']), $d['length'],
            js($d['categories']),
            $d['limit'],
            $d['price'],
            js($d['tiers']),
            $d['sort']
        );
    }
    $sql .= "INSERT INTO event_distances (id,event_id,name,length,unit,categories,participant_limit,registered_count,price,price_tiers,sort_order) VALUES\n";
    $sql .= implode(",\n", $distRows) . ";\n\n";

    // Kody zniżkowe
    $sql .= sprintf(
        "INSERT INTO event_discount_codes (id,event_id,code,discount_type,discount_value,max_uses,used_count,distance_ids,active,sort_order) VALUES\n" .
        "(%s,%s,'PODLASIE10','percent',10.00,200,0,%s,1,0),\n" .
        "(%s,%s,'KLUB15','percent',15.00,100,0,%s,1,1);\n\n",
        sq("{$eventId}-dc1"), sq($eventId), js(["$eventId-d10k", "$eventId-d21k", "$eventId-d42k"]),
        sq("{$eventId}-dc2"), sq($eventId), js(["$eventId-d10k", "$eventId-d21k", "$eventId-d42k"])
    );

    // Merch
    $sql .= sprintf(
        "INSERT INTO event_merchandise (id,event_id,name,description,price,sizes,colors,image_url,available,sort_order) VALUES\n" .
        "(%s,%s,'Koszulka startowa','Koszulka techniczna finishera',59.00,%s,%s,NULL,1,0),\n" .
        "(%s,%s,'Pakiet VIP','Dodatkowy medal i czapeczka',89.00,%s,%s,NULL,1,1);\n\n",
        sq("{$eventId}-m1"), sq($eventId),
        js(['S','M','L','XL','XXL']),
        js([['id' => 'white', 'name' => 'Biały', 'hex' => '#FFFFFF'], ['id' => 'navy', 'name' => 'Granatowy', 'hex' => '#1E3A5F']]),
        sq("{$eventId}-m2"), sq($eventId),
        js([]),
        js([])
    );

    return $sql;
}

// ---------------------------------------------------------------------------
// Pomocnicza: kategoria wiekowa
// ---------------------------------------------------------------------------

function ageCategory(int $birthYear, string $gender): string
{
    $age = 2025 - $birthYear;
    if ($age < 18) return 'OPEN';
    if ($age < 30) return "{$gender}18";
    if ($age < 40) return "{$gender}30";
    if ($age < 50) return "{$gender}40";
    return "{$gender}50";
}

// ---------------------------------------------------------------------------
// Główna funkcja
// ---------------------------------------------------------------------------

function main(array $argv): void
{
    global $MALE_FIRST_NAMES, $FEMALE_FIRST_NAMES, $LAST_NAMES, $CLUBS, $CITIES;

    $outFile = __DIR__ . '/.generated/002_test_events.sql';
    $dryRun  = false;
    $rngSeed = DEFAULT_RNG_SEED;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--out=')) $outFile = substr($arg, 6);
        if (str_starts_with($arg, '--seed=')) $rngSeed = (int) substr($arg, 7);
        if ($arg === '--dry-run') $dryRun = true;
    }

    // Seeded RNG: the same seed yields byte-identical SQL, so a regenerated fixture set
    // is comparable across machines and reruns instead of drifting every time.
    mt_srand($rngSeed);

    $sql  = "-- Wygenerowano przez generate_events.php\n";
    $sql .= "-- Hasło wszystkich kont: demo1234\n";
    $sql .= "-- Ziarno RNG: {$rngSeed} (--seed=N zmienia zestaw danych)\n\n";

    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // -----------------------------------------------------------------------
    // Kredyty dla test-organizera (safe upsert)
    // -----------------------------------------------------------------------
    $sql .= "INSERT INTO organizer_event_credit_batches (id,organizer_id,event_credit_kind,total_count,used_count,source_type,source_id,created_at,expires_at) VALUES\n";
    $sql .= "('credit-test-org-std','test-organizer','standard',10,0,'demo_seed',NULL,'2026-01-01T00:00:00+00:00',NULL),\n";
    $sql .= "('credit-test-org-virt','test-organizer','virtual',5,0,'demo_seed',NULL,'2026-01-01T00:00:00+00:00',NULL),\n";
    $sql .= "('credit-test-org-timing','test-organizer','timing',5,0,'demo_seed',NULL,'2026-01-01T00:00:00+00:00',NULL)\n";
    $sql .= "ON DUPLICATE KEY UPDATE total_count=VALUES(total_count);\n\n";

    // -----------------------------------------------------------------------
    // Wydarzenie 1: Przeszłe + 120 uczestników
    // -----------------------------------------------------------------------
    $pastParticipants = generateParticipants(
        PAST_EVENT_PARTICIPANT_COUNT,
        'past',
        $MALE_FIRST_NAMES, $FEMALE_FIRST_NAMES, $LAST_NAMES, $CLUBS, $CITIES
    );

    $sql .= "-- ============================================================\n";
    $sql .= "-- WYDARZENIE 1: Leśny Bieg Testowy 2025 (przeszłe, 120 uczestników)\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= renderUsers($pastParticipants);
    $sql .= "\n";
    $sql .= buildPastEvent($pastParticipants, $MALE_FIRST_NAMES, $FEMALE_FIRST_NAMES, $LAST_NAMES);

    // -----------------------------------------------------------------------
    // Wydarzenie 2: Bieżące — Tri Kids
    // -----------------------------------------------------------------------
    $sql .= "-- ============================================================\n";
    $sql .= "-- WYDARZENIE 2: Tri Kids Mazury 2026 (bieżące, zapisy zamknięte 18.06)\n";
    $sql .= "-- ============================================================\n\n";
    $currentResult = buildCurrentEvent();
    $sql .= renderUsers($currentResult['participants']);
    $sql .= "\n";
    $sql .= $currentResult['sql'];

    // -----------------------------------------------------------------------
    // Wydarzenie 3: Nadchodzące
    // -----------------------------------------------------------------------
    $sql .= "-- ============================================================\n";
    $sql .= "-- WYDARZENIE 3: Grand Prix Podlasia 2027 (nadchodzące, zapisy otwarte)\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= buildUpcomingEvent();

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    if ($dryRun) {
        echo $sql;
    } else {
        $dir = dirname($outFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "Nie moge utworzyc katalogu: {$dir}\n");
            exit(1);
        }
        file_put_contents($outFile, $sql);
        echo "Wygenerowano: {$outFile}\n";
        echo "Uczestników przeszłego eventu: " . PAST_EVENT_PARTICIPANT_COUNT . "\n";
        echo "Uczestników bieżącego eventu: 40 (16 dzieci + 24 młodzież)\n";
        echo "Wydarzeń: 3\n";
    }
}

main($argv ?? []);
