<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/** Self-service cancellation window, refund request, counter and history. */
final class RegistrationSelfCancelContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private RegistrationRepository $registrations;
    private string $prefix;
    private array $participant;
    private array $stranger;
    private array $organizer;
    private array $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo);
        $this->prefix = 'http-selfcancel-' . bin2hex(random_bytes(4));

        $make = fn (string $label, string $role): array => $this->users->create([
            'name' => 'SC ' . $label,
            'email' => $this->prefix . '-' . $label . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ]);
        $this->participant = $make('participant', 'participant');
        $this->stranger = $make('stranger', 'participant');
        $this->organizer = $make('organizer', 'organizer');
        $this->event = $this->events->create([
            'name' => 'PHPUnit SelfCancel Event',
            'slug' => $this->prefix . '-event',
            'discipline' => 'running',
            'date' => (new \DateTimeImmutable('+90 days'))->format('Y-m-d'),
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'SC Organizer',
            'status' => 'active',
            'registrationDeadline' => (new \DateTimeImmutable('+60 days'))->format('Y-m-d'),
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $this->prefix . '-10k',
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
            ], [
                'id' => $this->prefix . '-5k',
                'name' => '5 km',
                'length' => 5,
                'unit' => 'km',
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'limit' => 100,
                'registeredCount' => 0,
                'price' => 50,
                'routeFiles' => [],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $this->event['id']]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            foreach (['registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_changes'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    /** @return array<string, mixed> */
    private function register(bool $paid): array
    {
        return $this->registrations->create([
            'eventId' => $this->event['id'],
            'eventName' => $this->event['name'],
            'userId' => $this->participant['id'],
            'userName' => $this->participant['name'],
            'userEmail' => $this->participant['email'],
            'distanceId' => $this->event['distances'][0]['id'],
            'distanceName' => $this->event['distances'][0]['name'],
            'category' => 'Open',
            'merchandiseOrders' => [],
            ...($paid ? ['paymentDetails' => ['startFeePaid' => true]] : []),
        ]);
    }

    /** @return array<string, string> */
    private function ownerAuth(): array
    {
        return $this->bearer($this->participant['id'], 'participant', $this->participant['email']);
    }

    private function registeredCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT registered_count FROM event_distances WHERE id = :id');
        $stmt->execute(['id' => $this->event['distances'][0]['id']]);

        return (int) $stmt->fetchColumn();
    }

    public function testOwnerCancelsUnpaidRegistrationInsideWindowAndSlotReturns(): void
    {
        $reg = $this->register(false);
        self::assertSame(1, $this->registeredCount());

        $response = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());
        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cancelled', $json['data']['status']);
        self::assertSame(0, $this->registeredCount());
        self::assertNull($this->registrations->find($reg['id'])['refund'] ?? null);
    }

    public function testOwnerCancellingPaidRegistrationFilesRefundRequest(): void
    {
        $reg = $this->register(true);

        $response = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());
        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cancelled', $json['data']['status']);
        self::assertSame('requested', $json['data']['refund']['status']);
        self::assertSame('self_cancel', $json['data']['refund']['reason']);
        self::assertSame(0, $this->registeredCount());
    }

    public function testOwnerCancellingAfterACheaperDistanceChangeRefundsEverythingPaid(): void
    {
        $reg = $this->register(true);
        $change = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/distance-change', ['distanceId' => $this->prefix . '-5k'], $this->ownerAuth());
        self::assertSame(200, $change->getStatusCode());
        self::assertSame('distance_change', $this->json($change)['data']['refund']['reason']);
        self::assertEquals(30, $this->json($change)['data']['refund']['amount']);

        $cancel = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());
        self::assertSame(200, $cancel->getStatusCode());
        $refund = $this->json($cancel)['data']['refund'];
        self::assertSame('requested', $refund['status']);
        self::assertSame('self_cancel', $refund['reason']);

        $approved = $this->registrations->approveRefund($reg['id'], $this->organizer['id']);

        self::assertEquals(80, $approved['refund']['amount'], 'everything the participant paid (50 + 30 overpayment), not just the overpayment');
    }

    public function testManagerCancellingAnAlreadyInactiveRegistrationChangesNothing(): void
    {
        $manager = $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
        foreach (['expired', 'removed', 'cancelled'] as $status) {
            $reg = $this->register(false);
            $this->pdo->prepare('UPDATE registrations SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $reg['id']]);

            $response = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $manager);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame($status, $this->json($response)['data']['status']);
            self::assertSame($status, $this->registrations->find($reg['id'])['status']);
            $history = $this->pdo->prepare('SELECT COUNT(*) FROM registration_changes WHERE registration_id = :id');
            $history->execute(['id' => $reg['id']]);
            self::assertSame(0, (int) $history->fetchColumn());
        }
    }

    public function testCancellationIsRecordedInHistoryForOwnerAndManager(): void
    {
        $reg = $this->register(true);
        $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());

        $owner = $this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->ownerAuth());
        $manager = $this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']));
        $stranger = $this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->bearer($this->stranger['id'], 'participant', $this->stranger['email']));
        $anonymous = $this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes');

        self::assertSame(200, $owner->getStatusCode());
        self::assertSame(200, $manager->getStatusCode());
        self::assertSame(403, $stranger->getStatusCode());
        self::assertSame(401, $anonymous->getStatusCode());
        $rows = $this->json($owner)['data'];
        self::assertCount(1, $rows);
        self::assertSame('cancelled', $rows[0]['type']);
        self::assertSame('confirmed', $rows[0]['from']['status']);
        self::assertTrue($rows[0]['to']['refundRequested']);
        self::assertSame('owner', $rows[0]['actorRole']);
        self::assertSame($this->participant['id'], $rows[0]['actorId']);
    }

    public function testOwnerIsRefusedAfterTheDefaultCutOff(): void
    {
        $reg = $this->register(true);
        // 3 days before the start: the default cut-off (7 days before) is behind us.
        $this->events->update((string) $this->event['id'], ['date' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d')]);

        $response = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());
        $json = $this->json($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('self_service_closed', $json['error']['code']);
        self::assertNotEmpty($json['error']['details']['deadline']);
        self::assertSame('confirmed', $this->registrations->find($reg['id'])['status']);
        self::assertSame(1, $this->registeredCount());
    }

    public function testOwnerIsRefusedWhenSelfServiceIsSwitchedOffButManagerStillCancels(): void
    {
        $reg = $this->register(false);
        $this->events->update((string) $this->event['id'], ['selfServiceEnabled' => false]);

        $owner = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());
        self::assertSame(409, $owner->getStatusCode());
        self::assertSame('self_service_disabled', $this->json($owner)['error']['code']);

        $manager = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']));
        self::assertSame(200, $manager->getStatusCode());
        self::assertSame('cancelled', $this->json($manager)['data']['status']);
    }

    public function testManagerCancelAfterCutOffKeepsOldBehaviourWithoutAutomaticRefund(): void
    {
        $reg = $this->register(true);
        $this->events->update((string) $this->event['id'], ['date' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d')]);

        $response = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']));
        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cancelled', $json['data']['status']);
        self::assertNull($json['data']['refund'] ?? null);
    }

    public function testStrangerCannotCancel(): void
    {
        $reg = $this->register(false);

        $response = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->bearer($this->stranger['id'], 'participant', $this->stranger['email']));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSecondOwnerCancelIsRefusedAndDoesNotDoubleCountHistory(): void
    {
        $reg = $this->register(false);
        $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());

        $again = $this->request('POST', '/api/v1/registrations/' . $reg['id'] . '/cancel', [], $this->ownerAuth());

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('registration_not_active', $this->json($again)['error']['code']);
        $history = $this->json($this->request('GET', '/api/v1/registrations/' . $reg['id'] . '/changes', [], $this->ownerAuth()))['data'];
        self::assertCount(1, $history);
    }

    public function testEventPatchValidatesAndExposesSelfServiceSettings(): void
    {
        $auth = $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
        $deadline = (new \DateTimeImmutable('+30 days'))->format('Y-m-d\TH:i');

        $ok = $this->request('PATCH', '/api/v1/events/' . $this->event['id'], ['selfServiceEnabled' => true, 'selfServiceDeadline' => $deadline], $auth);
        $bad = $this->request('PATCH', '/api/v1/events/' . $this->event['id'], ['selfServiceDeadline' => (new \DateTimeImmutable('+120 days'))->format('Y-m-d')], $auth);

        self::assertSame(200, $ok->getStatusCode());
        $data = $this->json($ok)['data'];
        self::assertTrue($data['selfServiceEnabled']);
        self::assertSame($deadline, (new \DateTimeImmutable($data['selfServiceDeadline']))->format('Y-m-d\TH:i'));
        self::assertSame($data['selfServiceDeadline'], $data['selfServiceEffectiveDeadline']);
        self::assertSame(422, $bad->getStatusCode());

        $public = $this->json($this->request('GET', '/api/v1/events/' . $this->event['id']));
        self::assertArrayHasKey('selfServiceEffectiveDeadline', $public['data']);
    }
}
