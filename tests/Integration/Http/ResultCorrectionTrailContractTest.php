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

    public function testNetTimeCorrectionIsStoredAndTrailedFromTo(): void
    {
        $this->patch(['netTime' => '00:44:30', 'netTimeInSeconds' => 2670]);
        $this->patch(['netTime' => '00:44:00', 'netTimeInSeconds' => 2640, 'reason' => 'Druga korekta']);

        // Both corrections can share one second, so the order of the rows is not asserted.
        $rows = $this->corrections();
        self::assertCount(2, $rows);
        $netChanges = array_map(static fn (array $row): array => $row['changes']['netTime'], $rows);
        self::assertContainsEquals(['from' => '00:44:30', 'to' => '00:44:00'], $netChanges);
        self::assertContainsEquals(['from' => null, 'to' => '00:44:30'], $netChanges);
        self::assertArrayNotHasKey('time', $rows[0]['changes']);
    }

    public function testPlacesCorrectionIsAppliedSurvivesRenumberingAndIsTrailed(): void
    {
        $patch = $this->patch(['position' => 3, 'categoryPosition' => 2, 'reason' => 'Decyzja sedziego']);
        self::assertSame(200, $patch->getStatusCode());

        $data = $this->json($patch)['data'];
        self::assertSame(3, (int) $data['position']);
        self::assertSame(2, (int) $data['categoryPosition']);
        $changes = $this->corrections()[0]['changes'];
        self::assertEquals(['from' => 1, 'to' => 3], $changes['position']);
        self::assertEquals(['from' => 1, 'to' => 2], $changes['categoryPosition']);
        self::assertArrayNotHasKey('time', $changes);
    }

    public function testManualPlaceSurvivesUpdateOfAnotherResultAndNullReleasesIt(): void
    {
        $this->patch(['position' => 5, 'categoryPosition' => 4]);
        $other = $this->json($this->request('POST', '/api/v1/results', [
            'eventId' => $this->event['id'], 'distanceId' => $this->distanceId, 'userName' => 'Drugi',
            'category' => 'Open', 'time' => '00:40:00', 'timeInSeconds' => 2400, 'status' => 'finished',
        ], $this->auth($this->organizer)))['data'];
        $this->request('PATCH', '/api/v1/results/' . $other['id'], ['bibNumber' => '9'], $this->auth($this->organizer));

        $mine = $this->stored();
        self::assertSame(5, (int) $mine['position']);
        self::assertSame(4, (int) $mine['categoryPosition']);

        $released = $this->json($this->patch(['position' => null]))['data'];
        self::assertSame(2, (int) $released['position']);
        self::assertSame(4, (int) $released['categoryPosition'], 'category place stays manual');
    }

    public function testNullPlaceKeepsAutomaticRanking(): void
    {
        $patch = $this->patch(['position' => null, 'categoryPosition' => null]);

        self::assertSame(200, $patch->getStatusCode());
        self::assertSame(1, (int) $this->json($patch)['data']['position']);
        self::assertSame([], $this->corrections());
    }

    /** @dataProvider invalidPlaces */
    public function testInvalidPlaceIs422(string $field, mixed $value): void
    {
        self::assertSame(422, $this->patch([$field => $value])->getStatusCode());
        self::assertSame([], $this->corrections());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPlaces(): iterable
    {
        yield 'position zero' => ['position', 0];
        yield 'position negative' => ['position', -2];
        yield 'position text' => ['position', 'abc'];
        yield 'position fraction' => ['position', 1.5];
        yield 'category zero' => ['categoryPosition', 0];
        yield 'category negative' => ['categoryPosition', -1];
    }

    public function testPlaceIsIgnoredForNonClassifiedStatus(): void
    {
        $patch = $this->patch(['status' => 'dnf', 'position' => 5]);

        self::assertSame(0, (int) $this->json($patch)['data']['position']);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $stmt = $this->pdo->prepare('SELECT position, category_position AS categoryPosition FROM results WHERE id = :id');
        $stmt->execute(['id' => $this->resultId]);

        return $stmt->fetch();
    }

    /** @param array<string, mixed> $payload */
    private function patch(array $payload): ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/results/' . $this->resultId, $payload, $this->auth($this->organizer));
    }

    /** @return array<int, array<string, mixed>> */
    private function corrections(): array
    {
        return $this->json($this->request('GET', '/api/v1/results/' . $this->resultId . '/corrections', [], $this->auth($this->organizer)))['data'];
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
