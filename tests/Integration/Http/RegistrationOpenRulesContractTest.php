<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * B-01 (spec 08, umowa §5.4): POST /registrations enforces "are registrations open" on the server
 * (published, status, open date, deadline, event date), with a 409 `registration_closed`.
 * The Event's own organizer / staff are exempt from the time rules (they add people after close).
 * G-08: on a Draft in test mode the request is a dry run - nothing real is created.
 */
final class RegistrationOpenRulesContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private string $prefix;
    private array $participant;
    private array $organizer;
    private array $event;
    private string $distanceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->prefix = 'http-regopen-' . bin2hex(random_bytes(4));
        $this->participant = $this->makeUser('p', 'participant');
        $this->organizer = $this->makeUser('org', 'organizer');

        $slug = $this->prefix . '-event';
        $this->distanceId = $slug . '-d';
        $this->event = $this->events->create([
            'name' => 'PHPUnit RegOpen Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+90 days')),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'RegOpen Org',
            'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+60 days')),
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
                'price' => 80,
                'routeFiles' => [],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testOpenPublishedEventAcceptsRegistration(): void
    {
        $response = $this->post($this->participant);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->countRegistrations());
    }

    /** @dataProvider closedStates */
    public function testClosedEventRejectsRegistrationWith409(array $columns): void
    {
        $this->setEvent($columns);

        $response = $this->post($this->participant);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('registration_closed', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRegistrations());
    }

    public static function closedStates(): array
    {
        return [
            'finished' => [['publication_status' => 'finished']],
            'cancelled' => [['publication_status' => 'cancelled']],
            'closed by organizer' => [['registration_status' => 'closed']],
            'registration not started (none)' => [['registration_status' => 'none']],
            'open date in the future' => [['registration_open_date' => date('Y-m-d', strtotime('+5 days'))]],
            'deadline passed' => [['registration_deadline' => date('Y-m-d', strtotime('-1 day'))]],
            'event date passed' => [['date' => date('Y-m-d', strtotime('-1 day')), 'registration_deadline' => date('Y-m-d', strtotime('-3 days'))]],
        ];
    }

    public function testDeadlineDayItselfIsStillOpen(): void
    {
        $this->setEvent(['registration_deadline' => date('Y-m-d')]);

        self::assertSame(201, $this->post($this->participant)->getStatusCode());
    }

    public function testEventOrganizerMayAddAfterRegistrationClosed(): void
    {
        $this->setEvent(['registration_status' => 'closed', 'registration_deadline' => date('Y-m-d', strtotime('-1 day'))]);

        self::assertSame(201, $this->post($this->organizer)->getStatusCode());
    }

    public function testOrganizerCannotRegisterOnUnpublishedNonTestEvent(): void
    {
        $this->setEvent(['publication_status' => 'draft']);

        $response = $this->post($this->organizer);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('registration_closed', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countRegistrations());
    }

    public function testTestModeDraftIsADryRunThatCreatesNothingReal(): void
    {
        $this->setEvent(['publication_status' => 'draft', 'is_test' => 1]);

        $response = $this->post($this->organizer);
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($data['isTest']);
        self::assertEmpty($data['paymentSession'] ?? null);
        self::assertSame(0, $this->countRegistrations());
        $count = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $count->execute(['id' => $this->distanceId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testTestModeDraftIsInvisibleToStrangers(): void
    {
        $this->setEvent(['publication_status' => 'draft', 'is_test' => 1]);

        self::assertSame(404, $this->post($this->participant)->getStatusCode());
    }

    /** @param array<string, mixed> $columns */
    private function setEvent(array $columns): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($columns)));
        $this->pdo->prepare("UPDATE events SET {$sets} WHERE id = :id__")->execute([...$columns, 'id__' => $this->event['id']]);
    }

    private function countRegistrations(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = :event');
        $stmt->execute(['event' => $this->event['id']]);
        return (int) $stmt->fetchColumn();
    }

    private function makeUser(string $slug, string $role): array
    {
        return $this->users->create([
            'name' => 'RegOpen ' . ucfirst($slug),
            'email' => $this->prefix . '-' . $slug . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
    }

    private function post(array $caller): ResponseInterface
    {
        return $this->request('POST', '/api/v1/registrations', [
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => $caller['id'],
            'userName' => $caller['name'],
            'userEmail' => $caller['email'],
            'distanceId' => $this->distanceId,
            'category' => 'Open',
            'merchandiseOrders' => [],
        ], $this->bearer($caller['id'], (string) $caller['role'], $caller['email']));
    }
}
