<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * `GET /events/{eventId}/start-list` (anonymous) must return only the
 * allowlisted DTO (RegistrationRepository::PUBLIC_START_LIST_FIELDS), never
 * userEmail/qrCode/discountCode/checkedInBy or any other financial/PII field,
 * and must never leak an anonymized competitor's userId.
 */
final class PublicStartListContractTest extends HttpTestCase
{
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private FormFieldRepository $formFields;
    private string $prefix;
    private array $event;
    private array $r1;
    private array $r2Competitor;
    private array $r2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->formFields = new FormFieldRepository($this->pdo);
        $this->prefix = 'http-sl-' . bin2hex(random_bytes(4));

        $this->event = $this->events->create($this->eventPayload());
        $this->formFields->ensureSystemCatalog((string) $this->event['id']);
        foreach (['contact_email', 'phone'] as $key) {
            $field = $this->findFieldByKey($key);
            $this->formFields->updatePublicListVisibility((string) $field['id'], true);
        }

        // R1: normal, checked in, with a merchandise order.
        $competitor1 = $this->competitors->create(['firstName' => 'PHPUnit R1', 'lastName' => 'Normal'], skipDuplicateCheck: true);
        $this->r1 = $this->registrations->create($this->registrationPayload($competitor1, 'PHPUnit R1 Normal', 'r1-' . $this->prefix . '@example.test'));
        $this->registrations->update((string) $this->r1['id'], ['checkedInAt' => '2026-01-01T08:00:00Z', 'checkedInBy' => 'staff-1']);
        $this->pdo->prepare('UPDATE registrations SET discount_code = :code WHERE id = :id')
            ->execute(['code' => 'SECRETCODE', 'id' => $this->r1['id']]);
        $this->pdo->prepare('INSERT INTO registration_merchandise_orders (id, registration_id, merchandise_id, merchandise_name, quantity, price) VALUES (:id, :rid, :mid, :name, 1, 10)')
            ->execute(['id' => bin2hex(random_bytes(16)), 'rid' => $this->r1['id'], 'mid' => bin2hex(random_bytes(16)), 'name' => 'PHPUnit koszulka']);

        // R2: anonymized competitor.
        $this->r2Competitor = $this->competitors->create(['firstName' => 'PHPUnit R2', 'lastName' => 'Anon'], skipDuplicateCheck: true);
        $this->r2 = $this->registrations->create($this->registrationPayload($this->r2Competitor, 'PHPUnit R2 Anon', 'r2-' . $this->prefix . '@example.test'));
        $this->competitors->setAnonymization((string) $this->r2Competitor['id'], true);

        // R3: pending, no extra data.
        $competitor3 = $this->competitors->create(['firstName' => 'PHPUnit R3', 'lastName' => 'Pending'], skipDuplicateCheck: true);
        $this->registrations->create($this->registrationPayload($competitor3, 'PHPUnit R3 Pending', 'r3-' . $this->prefix . '@example.test'));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $eventId = (string) ($this->event['id'] ?? '');
        if ($eventId !== '') {
            $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $stmt->execute(['id' => $eventId]);
            foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
                $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $regId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $regId]);
            }
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM form_field_answers WHERE form_field_id IN (SELECT id FROM form_fields WHERE event_id = :id)')->execute(['id' => $eventId]);
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $eventId]);
        }
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name LIKE :name')->execute(['name' => 'PHPUnit R%']);
    }

    public function testEveryEntryCarriesExactlyTheAllowlistedKeys(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->event['id'] . '/start-list');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertNotEmpty($data);

        $expectedKeys = [
            'id', 'distanceId', 'distanceName', 'category', 'userId', 'userName', 'bibNumber',
            'teamName', 'teamId', 'status', 'registeredAt', 'hasMerchandise', 'formFields',
        ];
        foreach ($data as $entry) {
            self::assertSame($expectedKeys, array_keys($entry));
        }
        self::assertSame($expectedKeys, RegistrationRepository::PUBLIC_START_LIST_FIELDS);
    }

    public function testNoSensitiveValueAppearsAnywhereInTheBody(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->event['id'] . '/start-list');
        $body = (string) $response->getBody();

        foreach (['userEmail', 'qrCode', 'discountCode', 'checkedInBy', 'competitorId', 'settlementItems', 'totalAmount'] as $forbiddenKey) {
            self::assertStringNotContainsString($forbiddenKey, $body, $forbiddenKey);
        }
        self::assertStringNotContainsString('SECRETCODE', $body);
        self::assertStringNotContainsString('staff-1', $body);
        self::assertStringNotContainsString('r1-' . $this->prefix . '@example.test', $body);
        self::assertStringNotContainsString((string) $this->r1['qrCode'], $body);
    }

    public function testAnonymizedEntryHasNoUserIdAndNonAnonymizedKeepsIt(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->event['id'] . '/start-list');
        $data = $this->json($response)['data'];

        $anonymizedEntry = current(array_filter($data, fn (array $e): bool => (string) $e['id'] === (string) $this->r2['id']));
        self::assertNotFalse($anonymizedEntry);
        self::assertNull($anonymizedEntry['userId']);

        $normalEntry = current(array_filter($data, fn (array $e): bool => (string) $e['id'] === (string) $this->r1['id']));
        self::assertNotFalse($normalEntry);
        self::assertSame((string) $this->r1['userId'], $normalEntry['userId']);

        self::assertStringNotContainsString((string) $this->r2['userId'], (string) $response->getBody());
    }

    public function testContactEmailNeverAppearsInFormFieldsButPhoneStillDoes(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->event['id'] . '/start-list');
        $data = $this->json($response)['data'];

        $entry = current(array_filter($data, fn (array $e): bool => (string) $e['id'] === (string) $this->r1['id']));
        self::assertNotFalse($entry);
        $keys = array_column($entry['formFields'], 'fieldKey');
        self::assertNotContains('contact_email', $keys);
        self::assertContains('phone', $keys);
    }

    public function testHasMerchandiseReflectsOrders(): void
    {
        $response = $this->request('GET', '/api/v1/events/' . $this->event['id'] . '/start-list');
        $data = $this->json($response)['data'];

        $withOrder = current(array_filter($data, fn (array $e): bool => (string) $e['id'] === (string) $this->r1['id']));
        self::assertTrue($withOrder['hasMerchandise']);

        $withoutOrder = current(array_filter($data, fn (array $e): bool => (string) $e['id'] === (string) $this->r2['id']));
        self::assertFalse($withoutOrder['hasMerchandise']);
    }

    public function testPaidOnlyModeUsesTheSameShape(): void
    {
        $this->events->update((string) $this->event['id'], ['displayOptions' => ['hideUnpaid' => true]]);
        $this->registrations->update((string) $this->r1['id'], ['status' => 'confirmed']);

        $response = $this->request('GET', '/api/v1/events/' . $this->event['id'] . '/start-list');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertNotEmpty($data);
        foreach ($data as $entry) {
            self::assertSame(RegistrationRepository::PUBLIC_START_LIST_FIELDS, array_keys($entry));
        }
    }

    private function findFieldByKey(string $key): array
    {
        foreach ($this->formFields->listForEvent((string) $this->event['id']) as $field) {
            if ($field['fieldKey'] === $key) {
                return $field;
            }
        }
        self::fail('Field not found: ' . $key);
    }

    private function registrationPayload(array $competitor, string $userName, string $userEmail): array
    {
        return [
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'competitorId' => $competitor['id'],
            'userId' => 'u-' . bin2hex(random_bytes(6)),
            'userName' => $userName,
            'userEmail' => $userEmail,
            'distanceId' => $this->event['distances'][0]['id'],
            'distanceName' => $this->event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
        ];
    }

    private function eventPayload(): array
    {
        $slug = $this->prefix . '-event';

        return [
            'name' => 'PHPUnit Public Start List Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => 'phpunit-organizer-' . $this->prefix,
            'organizerName' => 'PHPUnit Organizer',
            'status' => 'active',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'displayOptions' => ['showParticipants' => 'all'],
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
        ];
    }
}
