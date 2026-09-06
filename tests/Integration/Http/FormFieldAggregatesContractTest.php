<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E2.5: GET /events/{id}/form-field-aggregates is gated the same way as the
 * rest of the form-fields group ($canManageEvent — organizer of their own
 * event, or admin/moderator) — mirrors ActivityLogContractTest's
 * owner-vs-stranger auth shape for an organizer-scoped GET endpoint.
 */
final class FormFieldAggregatesContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private FormFieldRepository $fields;
    private string $prefix;
    private array $organizer;
    private array $otherOrganizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->prefix = 'form-field-agg-' . bin2hex(random_bytes(4));

        $this->organizer = $this->users->create([
            'name' => 'Aggregates Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $this->otherOrganizer = $this->users->create([
            'name' => 'Aggregates Other Organizer',
            'email' => $this->prefix . '-other-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM form_field_answers WHERE form_field_id IN (SELECT id FROM form_fields WHERE event_id = :id)')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute([
            'email' => $this->prefix . '-%@example.test',
        ]);
    }

    public function testOrganizerCanReadOwnEventAggregatesButStrangerIsForbidden(): void
    {
        $event = $this->events->createForOrganizer($this->eventPayload(), $this->organizer, $this->organizer['id']);
        $this->fields->createCustom((string) $event['id'], [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'options' => [['key' => 's', 'label' => 'S', 'price' => 0], ['key' => 'm', 'label' => 'M', 'price' => 0]],
            'state' => 'shown',
        ]);

        $ownerResponse = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/form-field-aggregates',
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $ownerResponse->getStatusCode());
        $payload = $this->json($ownerResponse);
        self::assertTrue($payload['success']);
        self::assertCount(1, $payload['data']);
        self::assertSame('single_choice', $payload['data'][0]['fieldType']);
        self::assertSame(['s' => 0, 'm' => 0], array_column($payload['data'][0]['counts'], 'count', 'key'));

        $strangerResponse = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/form-field-aggregates',
            [],
            $this->bearer($this->otherOrganizer['id'], 'organizer', $this->otherOrganizer['email'])
        );
        self::assertSame(403, $strangerResponse->getStatusCode());
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = $this->prefix . '-event';

        return array_replace_recursive([
            'name' => 'Aggregates Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
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
            ],
        ], $overrides);
    }
}
