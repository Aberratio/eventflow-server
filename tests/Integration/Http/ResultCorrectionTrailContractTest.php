<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * G-03 (spec 14 pkt 6): a correction of a result (also after official publication) leaves a
 * durable trail - who, when, previous and new value of gross/net time, status and place, optional
 * reason - readable via GET /results/{id}/corrections with the same RBAC as editing the result.
 */
final class ResultCorrectionTrailContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $organizer;
    private array $otherOrganizer;
    private array $participant;
    private array $event;
    private string $distanceId;
    private string $resultId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-rescorr-' . bin2hex(random_bytes(4));
        $this->organizer = $this->makeUser('org', 'organizer');
        $this->otherOrganizer = $this->makeUser('org2', 'organizer');
        $this->participant = $this->makeUser('run', 'participant');

        $slug = $this->prefix . '-event';
        $this->distanceId = $slug . '-d';
        $this->event = $this->events->create([
            'name' => 'PHPUnit ResCorr Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('-1 day')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'ResCorr Org',
            'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('-5 days')),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->distanceId,
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 0,
                'routeFiles' => [],
            ]],
        ]);

        $created = $this->request('POST', '/api/v1/results', [
            'eventId' => $this->event['id'],
            'eventName' => 'PHPUnit ResCorr Event',
            'distanceId' => $this->distanceId,
            'distanceName' => '10 km',
            'userId' => $this->participant['id'],
            'userName' => 'Runner',
            'bibNumber' => '7',
            'category' => 'Open',
            'time' => '00:45:00',
            'timeInSeconds' => 2700,
            'position' => 1,
            'categoryPosition' => 1,
            'status' => 'finished',
        ], $this->auth($this->organizer));
        $this->resultId = (string) $this->json($created)['data']['id'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM result_corrections WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM results WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCorrectionAfterOfficialPublicationLeavesWhoWhenWhatAndWhy(): void
    {
        $this->markOfficial();

        $patch = $this->request('PATCH', '/api/v1/results/' . $this->resultId, [
            'time' => '00:44:10',
            'timeInSeconds' => 2650,
            'status' => 'dsq',
            'reason' => 'Bledny odczyt maty',
        ], $this->auth($this->organizer));
        self::assertSame(200, $patch->getStatusCode());

        $list = $this->request('GET', '/api/v1/results/' . $this->resultId . '/corrections', [], $this->auth($this->organizer));
        self::assertSame(200, $list->getStatusCode());
        $rows = $this->json($list)['data'];
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($this->organizer['id'], $row['correctedBy']);
        self::assertSame('Bledny odczyt maty', $row['reason']);
        self::assertTrue($row['wasOfficial']);
        self::assertNotEmpty($row['correctedAt']);
        self::assertEquals(['from' => '00:45:00', 'to' => '00:44:10'], $row['changes']['time']);
        self::assertEquals(['from' => 'finished', 'to' => 'dsq'], $row['changes']['status']);
        self::assertEquals(['from' => 1, 'to' => 0], $row['changes']['position']);
    }

    public function testTrailSurvivesActivityLogPurge(): void
    {
        $this->request('PATCH', '/api/v1/results/' . $this->resultId, ['time' => '00:44:00', 'timeInSeconds' => 2640], $this->auth($this->organizer));
        $this->pdo->prepare("DELETE FROM activity_logs WHERE target_id = :id")->execute(['id' => $this->resultId]);

        $rows = $this->json($this->request('GET', '/api/v1/results/' . $this->resultId . '/corrections', [], $this->auth($this->organizer)))['data'];

        self::assertCount(1, $rows);
    }

    public function testEditThatChangesNothingTrackedLeavesNoTrail(): void
    {
        $this->request('PATCH', '/api/v1/results/' . $this->resultId, ['bibNumber' => '8'], $this->auth($this->organizer));

        $rows = $this->json($this->request('GET', '/api/v1/results/' . $this->resultId . '/corrections', [], $this->auth($this->organizer)))['data'];

        self::assertSame([], $rows);
    }

    public function testOnlyThoseWhoMayEditTheResultMayReadItsCorrections(): void
    {
        $url = '/api/v1/results/' . $this->resultId . '/corrections';

        self::assertSame(403, $this->request('GET', $url, [], $this->auth($this->otherOrganizer))->getStatusCode());
        self::assertSame(403, $this->request('GET', $url, [], $this->auth($this->participant))->getStatusCode());
        self::assertSame(401, $this->request('GET', $url)->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/v1/results/nope/corrections', [], $this->auth($this->organizer))->getStatusCode());
    }

    private function markOfficial(): ResponseInterface
    {
        return $this->request(
            'POST',
            '/api/v1/events/' . $this->event['id'] . '/distances/' . $this->distanceId . '/results/publish-official',
            [],
            $this->auth($this->organizer)
        );
    }

    /** @return array<string, string> */
    private function auth(array $user): array
    {
        return $this->bearer($user['id'], (string) $user['role'], $user['email']);
    }

    private function makeUser(string $slug, string $role): array
    {
        return $this->users->create([
            'name' => 'ResCorr ' . ucfirst($slug),
            'email' => $this->prefix . '-' . $slug . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }
}
