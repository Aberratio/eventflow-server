<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Care\CareRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * R-08 (spec 22): opiekun / organizator zgłasza możliwy duplikat Zawodnika,
 * Moderator/Admin widzi zgłoszenia w kolejce obok scalania profili.
 */
final class CompetitorDuplicateReportContractTest extends HttpTestCase
{
    private string $prefix;
    /** @var array<string, array<string, mixed>> */
    private array $actors = [];
    private array $child;
    private array $other;

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

    public function testOrganizerCanReport(): void
    {
        self::assertSame(201, $this->report('organizer')->getStatusCode());
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
