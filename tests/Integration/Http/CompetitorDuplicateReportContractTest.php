<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * A guardian / organizer reports a possible duplicate Zawodnik,
 * Moderator/Admin sees the reports in a queue next to profile merging.
 */
final class CompetitorDuplicateReportContractTest extends HttpTestCase
{
    private string $prefix;
    /** @var array<string, array<string, mixed>> */
    private array $actors = [];
    private array $child;
    private array $other;
    /** @var list<string> */
    private array $eventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'DupRep-' . bin2hex(random_bytes(4));
        $users = new UserRepository($this->pdo);
        foreach (['participant', 'caregiver', 'organizer', 'moderator', 'admin'] as $role) {
            $this->actors[$role] = $users->create([
                'name' => 'Dup ' . $role,
                'email' => strtolower($this->prefix) . '-' . $role . '@example.test',
                'password' => 'demo1234',
                'role' => $role === 'caregiver' ? 'participant' : $role,
            ]);
        }
        $competitors = new CompetitorRepository($this->pdo);
        $this->child = $competitors->create(['firstName' => 'Dup', 'lastName' => $this->prefix]);
        $this->other = $competitors->create(['firstName' => 'Dup', 'lastName' => $this->prefix]);
        (new CareRepository($this->pdo))->grant((string) $this->actors['caregiver']['id'], (string) $this->child['id']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        foreach ($this->eventIds as $eventId) {
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :e')->execute(['e' => $eventId]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :e')->execute(['e' => $eventId]);
        }
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => strtolower($this->prefix) . '-%@example.test']);
    }

    private function as(string $role): array
    {
        $role = $role;
        $user = $this->actors[$role];
        return $this->bearer((string) $user['id'], $role === 'caregiver' ? 'participant' : $role, (string) $user['email']);
    }

    private function report(string $role, array $body = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'POST',
            "/api/v1/competitors/{$this->child['id']}/duplicate-reports",
            $body + ['duplicateOfId' => $this->other['id'], 'note' => 'To ta sama osoba'],
            $this->as($role)
        );
    }

    public function testCaregiverCanReportAndStaffSeesItInQueue(): void
    {
        $created = $this->report('caregiver');
        self::assertSame(201, $created->getStatusCode());
        $data = $this->json($created)['data'];
        self::assertSame('open', $data['status']);
        self::assertSame($this->child['id'], $data['competitorId']);
        self::assertSame($this->other['id'], $data['duplicateOfId']);

        $list = $this->request('GET', '/api/v1/admin/competitors/duplicate-reports', [], $this->as('moderator'));
        self::assertSame(200, $list->getStatusCode());
        $rows = array_values(array_filter($this->json($list)['data'], fn ($r) => $r['competitorId'] === $this->child['id']));
        self::assertCount(1, $rows);
        self::assertSame('To ta sama osoba', $rows[0]['note']);
        self::assertSame('Dup', $rows[0]['competitor']['firstName']);
        self::assertArrayNotHasKey('identityCode', $rows[0]['competitor']);
    }

    public function testOrganizerWithRegistrationOnOwnEventCanReport(): void
    {
        $this->registerChildOn((string) $this->actors['organizer']['id']);
        self::assertSame(201, $this->report('organizer')->getStatusCode());
    }

    public function testOrganizerWithoutRegistrationOnOwnEventCannotReport(): void
    {
        self::assertSame(403, $this->report('organizer')->getStatusCode());
        // zapis na cudze wydarzenie nie wystarcza
        $this->registerChildOn((string) $this->actors['participant']['id']);
        self::assertSame(403, $this->report('organizer')->getStatusCode());
    }

    public function testModeratorAndAdminCanReportAnyone(): void
    {
        self::assertSame(201, $this->report('moderator')->getStatusCode());
        self::assertSame(201, $this->report('admin')->getStatusCode());
    }

    public function testModeratorCanRejectWithReasonAndQueueFiltersByStatus(): void
    {
        $id = $this->json($this->report('caregiver'))['data']['id'];
        $url = "/api/v1/admin/competitors/duplicate-reports/{$id}";

        self::assertSame(422, $this->request('PATCH', $url, ['status' => 'merged'], $this->as('moderator'))->getStatusCode());

        $res = $this->request('PATCH', $url, ['status' => 'rejected', 'reason' => 'Różne osoby'], $this->as('moderator'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('rejected', $this->json($res)['data']['status']);
        self::assertSame('Różne osoby', $this->json($res)['data']['rejectReason']);

        self::assertSame(409, $this->request('PATCH', $url, ['status' => 'rejected'], $this->as('moderator'))->getStatusCode());
        self::assertSame(404, $this->request('PATCH', '/api/v1/admin/competitors/duplicate-reports/nope', ['status' => 'rejected'], $this->as('moderator'))->getStatusCode());
        self::assertSame(403, $this->request('PATCH', $url, ['status' => 'rejected'], $this->as('organizer'))->getStatusCode());

        $ids = fn (string $q): array => array_values(array_column(array_filter(
            $this->json($this->request('GET', '/api/v1/admin/competitors/duplicate-reports' . $q, [], $this->as('admin')))['data'],
            fn ($r) => $r['competitorId'] === $this->child['id']
        ), 'id'));
        self::assertSame([], $ids(''));
        self::assertSame([$id], $ids('?status=rejected'));
        self::assertSame([$id], $ids('?status=all'));
        self::assertSame(422, $this->request('GET', '/api/v1/admin/competitors/duplicate-reports?status=bogus', [], $this->as('admin'))->getStatusCode());
    }

    public function testMergeMarksOpenReportsForMergedPairAsMerged(): void
    {
        $id = $this->json($this->report('caregiver'))['data']['id'];
        $merge = $this->request(
            'POST',
            '/api/v1/admin/competitors/merge',
            ['survivorId' => $this->child['id'], 'absorbedId' => $this->other['id']],
            $this->as('moderator')
        );
        self::assertSame(200, $merge->getStatusCode(), (string) $merge->getBody());

        $rows = $this->json($this->request('GET', '/api/v1/admin/competitors/duplicate-reports?status=merged', [], $this->as('admin')))['data'];
        self::assertContains($id, array_column($rows, 'id'));
        self::assertSame([], array_filter(
            $this->json($this->request('GET', '/api/v1/admin/competitors/duplicate-reports', [], $this->as('admin')))['data'],
            fn ($r) => $r['id'] === $id
        ));
    }

    private function registerChildOn(string $organizerId): void
    {
        $slug = strtolower($this->prefix) . '-' . bin2hex(random_bytes(3));
        $event = (new \ZmierzymyCzas\Domain\Events\EventRepository($this->pdo))->create([
            'name' => $slug, 'slug' => $slug, 'discipline' => 'running', 'date' => date('Y-m-d', strtotime('+60 days')),
            'location' => 'X', 'city' => 'Y', 'description' => 'd', 'imageUrl' => 'https://example.test/e.jpg',
            'organizerId' => $organizerId, 'organizerName' => 'Org', 'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+50 days')), 'featured' => false, 'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
                'id' => $slug . '-10k', 'name' => '10 km', 'length' => 10, 'unit' => 'km', 'categories' => ['Open'],
                'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0, 'price' => 80, 'routeFiles' => [],
            ]],
        ]);
        $this->eventIds[] = (string) $event['id'];
        $this->pdo->prepare(
            'INSERT INTO registrations (id, event_id, event_name, user_id, user_name, user_email, distance_id, distance_name, category, registered_at, status, qr_code, competitor_id)
             VALUES (:id, :e, :en, :u, :un, :ue, :d, :dn, :cat, :at, :st, :qr, :c)'
        )->execute([
            'id' => $slug . '-r', 'e' => $event['id'], 'en' => $event['name'], 'u' => (string) $this->actors['caregiver']['id'],
            'un' => 'x', 'ue' => 'x@example.test', 'd' => $event['distances'][0]['id'], 'dn' => '10 km', 'cat' => 'Open',
            'at' => date(DATE_ATOM), 'st' => 'confirmed', 'qr' => 'dr_' . bin2hex(random_bytes(8)), 'c' => $this->child['id'],
        ]);
    }

    public function testUnrelatedParticipantCannotReport(): void
    {
        self::assertSame(403, $this->report('participant')->getStatusCode());
    }

    public function testValidation(): void
    {
        self::assertSame(422, $this->report('caregiver', ['duplicateOfId' => ''])->getStatusCode());
        self::assertSame(422, $this->report('caregiver', ['duplicateOfId' => $this->child['id']])->getStatusCode());
        self::assertSame(404, $this->report('caregiver', ['duplicateOfId' => '00000000-0000-4000-8000-000000000000'])->getStatusCode());
    }

    public function testRepeatedReportIsIdempotentWhileOpen(): void
    {
        $a = $this->json($this->report('caregiver'))['data']['id'];
        $b = $this->json($this->report('caregiver'))['data']['id'];
        self::assertSame($a, $b);
    }

    public function testQueueIsStaffOnly(): void
    {
        foreach (['participant', 'organizer'] as $role) {
            self::assertSame(403, $this->request('GET', '/api/v1/admin/competitors/duplicate-reports', [], $this->as($role))->getStatusCode());
        }
    }
}
