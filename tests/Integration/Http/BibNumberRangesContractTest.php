<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Bib number ranges per distance, bulk assignment inside the range,
 * manual override with uniqueness (409) and range check (422).
 */
final class BibNumberRangesContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private string $prefix;
    private array $organizer;
    private array $stranger;
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->prefix = 'r13-' . bin2hex(random_bytes(4));
        $this->organizer = $this->users->create([
            'name' => 'Bib Organizer', 'email' => $this->prefix . '-org@example.test', 'password' => 'demo1234',
            'role' => 'organizer', 'eventLimit' => 5,
        ]);
        $this->stranger = $this->users->create([
            'name' => 'Bib Stranger', 'email' => $this->prefix . '-str@example.test', 'password' => 'demo1234',
            'role' => 'organizer', 'eventLimit' => 5,
        ]);
        $this->auth = $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :n')->execute(['n' => 'Bib Contract%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOverlappingRangesAreRejectedWith422AndDetails(): void
    {
        [$eventId, $d10, $d5] = $this->createEvent();
        $response = $this->request('PATCH', '/api/v1/events/' . $eventId, ['distances' => [
            $this->distance($d10, '10 km', 1, 100),
            $this->distance($d5, '5 km', 100, 200),
        ]], $this->auth);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('bib_range_overlap', $error['code']);
        self::assertSame('distances[1].bibRangeFrom', $error['details']['field']);
    }

    public function testInvalidRangeIsRejected(): void
    {
        [$eventId, $d10, $d5] = $this->createEvent();
        $response = $this->request('PATCH', '/api/v1/events/' . $eventId, ['distances' => [
            $this->distance($d10, '10 km', 50, 10),
            $this->distance($d5, '5 km', null, null),
        ]], $this->auth);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('bib_range_invalid', $this->json($response)['error']['code']);

        $half = $this->request('PATCH', '/api/v1/events/' . $eventId, ['distances' => [
            $this->distance($d10, '10 km', 1, null),
        ]], $this->auth);
        self::assertSame(422, $half->getStatusCode());
    }

    public function testDisjointRangesArePersistedAndReturned(): void
    {
        [$eventId, $d10, $d5] = $this->createEvent();
        $response = $this->request('PATCH', '/api/v1/events/' . $eventId, ['distances' => [
            $this->distance($d10, '10 km', 1, 99),
            $this->distance($d5, '5 km', 100, 199),
        ]], $this->auth);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $event = $this->events->find($eventId);
        $byId = [];
        foreach ($event['distances'] as $d) {
            $byId[$d['id']] = $d;
        }
        self::assertSame(1, (int) $byId[$d10]['bibRangeFrom']);
        self::assertSame(99, (int) $byId[$d10]['bibRangeTo']);
        self::assertSame(100, (int) $byId[$d5]['bibRangeFrom']);
    }

    public function testBulkAssignGivesNextFreeNumbersInsideEachRange(): void
    {
        [$eventId, $d10, $d5] = $this->createEvent();
        $this->setRanges($eventId, $d10, $d5, [1, 3], [100, 150]);
        $a = $this->register($eventId, $d10, 'Anna', 'confirmed');
        $b = $this->register($eventId, $d10, 'Beata', 'confirmed');
        $c = $this->register($eventId, $d5, 'Celina', 'confirmed');
        $pending = $this->register($eventId, $d5, 'Dorota', 'pending');
        $this->pdo->prepare("UPDATE registrations SET bib_number = '101' WHERE id = :id")->execute(['id' => $c]);
        $c2 = $this->register($eventId, $d5, 'Ewa', 'confirmed');

        $response = $this->request('POST', '/api/v1/events/' . $eventId . '/bib-numbers/assign', [], $this->auth);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertSame(3, $data['assigned']);
        self::assertSame([], $data['skipped']);
        $pair = [$this->bibOf($a), $this->bibOf($b)];
        sort($pair);
        self::assertSame(['001', '002'], $pair);
        self::assertSame('100', $this->bibOf($c2));
        self::assertNull($this->bibOf($pending), 'paidOnly defaults to true');

        $again = $this->json($this->request('POST', '/api/v1/events/' . $eventId . '/bib-numbers/assign', ['paidOnly' => false], $this->auth))['data'];
        self::assertSame(1, $again['assigned']);
        self::assertSame('102', $this->bibOf($pending), 'skips the used 100 and 101');
    }

    public function testRangeExhaustionIsReportedAndNothingIsAssignedOutsideTheRange(): void
    {
        [$eventId, $d10, $d5] = $this->createEvent();
        $this->setRanges($eventId, $d10, $d5, [1, 1], null);
        $a = $this->register($eventId, $d10, 'Anna', 'confirmed');
        $b = $this->register($eventId, $d10, 'Beata', 'confirmed');
        $this->register($eventId, $d5, 'Celina', 'confirmed');

        $data = $this->json($this->request('POST', '/api/v1/events/' . $eventId . '/bib-numbers/assign', [], $this->auth))['data'];
        self::assertSame(1, $data['assigned']);
        self::assertSame(['001', null], [$this->bibOf($a) ?? $this->bibOf($b), $this->bibOf($a) !== null && $this->bibOf($b) !== null ? 'both' : null]);
        $reasons = [];
        foreach ($data['skipped'] as $s) {
            $reasons[$s['distanceId']] = $s['reason'];
        }
        self::assertSame('range_exhausted', $reasons[$d10]);
        self::assertSame('no_range', $reasons[$d5]);
    }

    public function testBulkAssignRequiresEventManager(): void
    {
        [$eventId] = $this->createEvent();
        $response = $this->request(
            'POST',
            '/api/v1/events/' . $eventId . '/bib-numbers/assign',
            [],
            $this->bearer($this->stranger['id'], 'organizer', $this->stranger['email'])
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testBulkAssignValidatesPayload(): void
    {
        [$eventId] = $this->createEvent();
        $url = '/api/v1/events/' . $eventId . '/bib-numbers/assign';
        self::assertSame(422, $this->request('POST', $url, ['order' => 'random'], $this->auth)->getStatusCode());
        self::assertSame(422, $this->request('POST', $url, ['distanceIds' => 'x'], $this->auth)->getStatusCode());
    }

    public function testManualOverrideEnforcesRangeAndUniquenessWithinEvent(): void
    {
        [$eventId, $d10, $d5] = $this->createEvent();
        $this->setRanges($eventId, $d10, $d5, [1, 99], [100, 199]);
        $a = $this->register($eventId, $d10, 'Anna', 'confirmed');
        $b = $this->register($eventId, $d10, 'Beata', 'confirmed');
        $c = $this->register($eventId, $d5, 'Celina', 'confirmed');

        $ok = $this->request('PATCH', '/api/v1/registrations/' . $a, ['bibNumber' => '7'], $this->auth);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame('7', $this->bibOf($a));

        $taken = $this->request('PATCH', '/api/v1/registrations/' . $b, ['bibNumber' => '007'], $this->auth);
        self::assertSame(409, $taken->getStatusCode());
        self::assertSame('bib_number_taken', $this->json($taken)['error']['code']);
        self::assertNull($this->bibOf($b));

        $outside = $this->request('PATCH', '/api/v1/registrations/' . $b, ['bibNumber' => '150'], $this->auth);
        self::assertSame(422, $outside->getStatusCode());
        self::assertSame('bib_out_of_range', $this->json($outside)['error']['code']);

        $same = $this->request('PATCH', '/api/v1/registrations/' . $a, ['bibNumber' => '7'], $this->auth);
        self::assertSame(200, $same->getStatusCode(), 'keeping its own number is not a collision');

        $inOther = $this->request('PATCH', '/api/v1/registrations/' . $c, ['bibNumber' => '120'], $this->auth);
        self::assertSame(200, $inOther->getStatusCode());

        $cleared = $this->request('PATCH', '/api/v1/registrations/' . $a, ['bibNumber' => null], $this->auth);
        self::assertSame(200, $cleared->getStatusCode());
        self::assertNull($this->bibOf($a));
    }

    public function testManualOverrideWithoutRangeOnlyChecksUniqueness(): void
    {
        [$eventId, $d10] = $this->createEvent();
        $a = $this->register($eventId, $d10, 'Anna', 'confirmed');
        $b = $this->register($eventId, $d10, 'Beata', 'confirmed');
        self::assertSame(200, $this->request('PATCH', '/api/v1/registrations/' . $a, ['bibNumber' => 'A12'], $this->auth)->getStatusCode());
        self::assertSame(409, $this->request('PATCH', '/api/v1/registrations/' . $b, ['bibNumber' => 'a12'], $this->auth)->getStatusCode());
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function createEvent(): array
    {
        $slug = $this->prefix . '-event-' . bin2hex(random_bytes(3));
        $event = $this->events->createForOrganizer([
            'name' => 'Bib Contract Event', 'slug' => $slug, 'discipline' => 'running', 'date' => '2026-09-20',
            'location' => 'Stadion', 'city' => 'Warszawa', 'description' => '<p>x</p>', 'imageUrl' => 'https://example.test/e.jpg',
            'organizerId' => $this->organizer['id'], 'organizerName' => $this->organizer['name'], 'status' => 'draft',
            'registrationDeadline' => '2026-09-01', 'featured' => false, 'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [
                $this->distance($slug . '-10k', '10 km', null, null),
                $this->distance($slug . '-5k', '5 km', null, null),
            ],
        ], $this->organizer, $this->organizer['id']);

        return [(string) $event['id'], (string) $event['distances'][0]['id'], (string) $event['distances'][1]['id']];
    }

    private function distance(string $id, string $name, ?int $from, ?int $to): array
    {
        return [
            'id' => $id, 'name' => $name, 'length' => 10, 'unit' => 'km', 'categories' => ['Open'], 'ageCategories' => [],
            'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0, 'price' => 80, 'routeFiles' => [],
            'bibRangeFrom' => $from, 'bibRangeTo' => $to,
        ];
    }

    /** @param array{0: int, 1: int}|null $r10 @param array{0: int, 1: int}|null $r5 */
    private function setRanges(string $eventId, string $d10, string $d5, ?array $r10, ?array $r5): void
    {
        $set = $this->pdo->prepare('UPDATE event_distances SET bib_range_from = :f, bib_range_to = :t WHERE id = :id AND event_id = :e');
        $set->execute(['f' => $r10[0] ?? null, 't' => $r10[1] ?? null, 'id' => $d10, 'e' => $eventId]);
        $set->execute(['f' => $r5[0] ?? null, 't' => $r5[1] ?? null, 'id' => $d5, 'e' => $eventId]);
    }

    private function register(string $eventId, string $distanceId, string $name, string $status): string
    {
        $competitor = $this->competitors->create(['firstName' => 'Bib Contract', 'lastName' => $name], skipDuplicateCheck: true);
        $reg = $this->registrations->create([
            'eventId' => $eventId, 'eventName' => 'Bib Contract Event', 'competitorId' => $competitor['id'],
            'userId' => $this->prefix . '-user-' . bin2hex(random_bytes(4)), 'userName' => $name,
            'userEmail' => $this->prefix . '-reg-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $distanceId, 'distanceName' => 'x', 'category' => 'Open',
        ]);
        $this->pdo->prepare('UPDATE registrations SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $reg['id']]);

        return (string) $reg['id'];
    }

    private function bibOf(string $registrationId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT bib_number FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $registrationId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
