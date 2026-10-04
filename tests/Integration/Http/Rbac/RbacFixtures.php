<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http\Rbac;

use PDO;
use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Results\ResultImportRepository;
use ZmierzymyCzas\Domain\Results\ResultRepository;
use ZmierzymyCzas\Domain\Teams\TeamRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;

/**
 * PP.4: the fixture world of the RBAC grid. Built once per test class inside the
 * class-wide outer transaction (SavepointPdo), never committed, so there is no cleanup.
 * Every fixture key is addressable from a grid row (`params`, `body` with '@key').
 */
final class RbacFixtures
{
    public const PASSWORD = 'rbac-pass-1234';

    /** @var array<string, string> */
    public array $values = [];

    public string $prefix = '';

    /** @var array<string, array{id: string, role: string, email: string}> actor => identity */
    public array $actors = [];

    private function __construct()
    {
    }

    public static function build(PDO $pdo): self
    {
        $fx = new self();
        $fx->prefix = 'rbac-' . bin2hex(random_bytes(4));

        $users = new UserRepository($pdo);
        $roles = [
            'participant' => 'participant',
            'owner' => 'participant',
            'foreignOrganizer' => 'organizer',
            'organizer' => 'organizer',
            'moderator' => 'moderator',
            'admin' => 'admin',
            'superadmin' => 'superadmin',
        ];
        foreach ($roles as $actor => $role) {
            $email = $fx->prefix . '-' . strtolower($actor) . '@example.test';
            $user = $users->create([
                'name' => 'Rbac ' . $actor,
                'email' => $email,
                'password' => self::PASSWORD,
                'role' => $role,
            ]);
            $fx->actors[$actor] = ['id' => (string) $user['id'], 'role' => $role, 'email' => $email];
            $fx->values[$actor] = (string) $user['id'];
        }
        $fx->values['ownerEmail'] = $fx->actors['owner']['email'];

        $events = new EventRepository($pdo);
        $organizerId = $fx->values['organizer'];
        $event = $events->create($fx->eventPayload('event', $organizerId, 'active', false));
        $draft = $events->create($fx->eventPayload('draft', $organizerId, 'draft', false));
        $private = $events->create($fx->eventPayload('private', $organizerId, 'active', true));
        $pdo->prepare('UPDATE events SET featured = 1, is_live = 1 WHERE id = :id')->execute(['id' => $private['id']]);

        $fx->values['event'] = (string) $event['id'];
        $fx->values['eventSlug'] = (string) $event['slug'];
        $fx->values['eventDraft'] = (string) $draft['id'];
        $fx->values['eventPrivate'] = (string) $private['id'];
        $fx->values['dist'] = (string) $event['distances'][0]['id'];
        $fx->values['relay'] = (string) $event['distances'][1]['id'];

        $registrationId = $fx->prefix . '-reg';
        $pdo->prepare(
            'INSERT INTO registrations (id, event_id, event_name, user_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, qr_code)
             VALUES (:id, :eventId, :eventName, :userId, :userName, :userEmail, :distanceId, :distanceName, :category, :registeredAt, :status, :qrCode)'
        )->execute([
            'id' => $registrationId,
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'userId' => $fx->values['owner'],
            'userName' => 'Rbac owner',
            'userEmail' => $fx->values['ownerEmail'],
            'distanceId' => $fx->values['dist'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'registeredAt' => date(DATE_ATOM),
            'status' => 'confirmed',
            'qrCode' => 'rbac_' . bin2hex(random_bytes(16)),
        ]);
        $fx->values['registration'] = $registrationId;

        $team = (new TeamRepository($pdo))->create([
            'eventId' => $event['id'],
            'distanceId' => $fx->values['relay'],
            'name' => 'Rbac team',
            'createdByUserId' => $fx->values['owner'],
        ]);
        $fx->values['team'] = (string) $team['id'];

        $fields = (new FormFieldRepository($pdo))->ensureSystemCatalog((string) $event['id']);
        $fx->values['formField'] = (string) $fields[0]['id'];

        $results = new ResultRepository($pdo);
        $result = $results->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'distanceId' => $fx->values['dist'],
            'distanceName' => $event['distances'][0]['name'],
            'userId' => $fx->values['owner'],
            'userName' => 'Rbac owner',
            'bibNumber' => 'R1',
            'category' => 'Open',
            'time' => '0:40:00',
            'timeInSeconds' => 2400,
            'position' => 1,
            'categoryPosition' => 1,
            'status' => 'finished',
        ]);
        $fx->values['result'] = (string) $result['id'];

        $csvPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $fx->prefix . '-import.csv';
        file_put_contents($csvPath, "miejsce,numer,uczestnik,czas,kategoria\n1,1,Rbac Runner,00:41:10,Open\n");
        $competitors = new CompetitorRepository($pdo);
        $imports = new ResultImportRepository($pdo, new RegistrationRepository($pdo), $competitors);
        $batch = $imports->create((string) $event['id'], $fx->values['dist'], 'replace', [
            'path' => $csvPath,
            'absolutePath' => $csvPath,
            'url' => '/tmp/' . basename($csvPath),
            'mimeType' => 'text/csv',
            'originalName' => 'rbac-import.csv',
        ], $fx->values['organizer']);
        @unlink($csvPath);
        $fx->values['importBatch'] = (string) $batch['id'];
        $fx->values['importRow'] = (string) $batch['rows'][0]['id'];

        $submissionId = $fx->prefix . '-vsub';
        $pdo->prepare(
            'INSERT INTO virtual_result_submissions (id, event_id, event_name, distance_id, distance_name, registration_id, user_id, user_name, user_email, submitted_time, submitted_time_in_seconds, submitted_at)
             VALUES (:id, :eventId, :eventName, :distanceId, :distanceName, :registrationId, :userId, :userName, :userEmail, :time, :seconds, :submittedAt)'
        )->execute([
            'id' => $submissionId,
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'distanceId' => $fx->values['dist'],
            'distanceName' => $event['distances'][0]['name'],
            'registrationId' => $registrationId,
            'userId' => $fx->values['owner'],
            'userName' => 'Rbac owner',
            'userEmail' => $fx->values['ownerEmail'],
            'time' => '0:50:00',
            'seconds' => 3000,
            'submittedAt' => date(DATE_ATOM),
        ]);
        $fx->values['virtualSubmission'] = $submissionId;

        $child = $competitors->create([
            'firstName' => 'Rbac',
            'lastName' => 'Child' . bin2hex(random_bytes(3)),
            'birthDate' => '2015-05-05',
            'gender' => 'M',
        ], true);
        $fx->values['child'] = (string) $child['id'];
        $care = (new CareRepository($pdo))->grant($fx->values['owner'], $fx->values['child']);
        $fx->values['care'] = (string) $care['id'];
        $other = $competitors->create([
            'firstName' => 'Rbac',
            'lastName' => 'Other' . bin2hex(random_bytes(3)),
            'birthDate' => '2016-06-06',
            'gender' => 'K',
        ], true);
        $fx->values['competitorB'] = (string) $other['id'];

        $fx->values['mailType'] = MailCatalog::PAYMENT_CONFIRMED;
        $fx->values['missing'] = 'rbac-missing-id';

        return $fx;
    }

    /** @return array<string, mixed> */
    public function eventPayload(string $name, string $organizerId, string $status, bool $private, array $overrides = []): array
    {
        $slug = $this->prefix . '-' . $name;
        $payload = [
            'name' => $this->prefix . ' ' . $name,
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+60 days')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $organizerId,
            'organizerName' => 'Rbac organizer',
            'status' => $status,
            'registrationDeadline' => date('Y-m-d', strtotime('+50 days')),
            'featured' => false,
            'isPrivate' => $private,
            'paymentConfig' => ['type' => 'admin_gateway'],
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
                [
                    'id' => $slug . '-relay',
                    'name' => 'Sztafeta 4x400m',
                    'length' => 1.6,
                    'unit' => 'km',
                    'categories' => ['Open'],
                    'ageCategories' => [],
                    'specialCategories' => [],
                    'limit' => 100,
                    'registeredCount' => 0,
                    'price' => 80,
                    'routeFiles' => [],
                    'startType' => 'relay',
                    'relayMinTeamSize' => 2,
                    'relayMaxTeamSize' => 4,
                    'teamAggregationRule' => 'sum',
                ],
            ],
        ];
        if ($private) {
            $payload['accessCode'] = 'rbac-code';
        }

        return array_replace($payload, $overrides);
    }

    /** @param array<string, string> $params */
    public function path(string $pattern, array $params): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z][A-Za-z0-9]*)(?::[^}]+)?\}/', function (array $m) use ($params): string {
            $key = $params[$m[1]] ?? 'missing';

            return rawurlencode($this->values[$key] ?? $key);
        }, $pattern);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function body(array $body): array
    {
        foreach ($body as $k => $v) {
            if (is_string($v) && str_starts_with($v, '@')) {
                $body[$k] = $this->values[substr($v, 1)] ?? $v;
            }
        }

        return $body;
    }
}
