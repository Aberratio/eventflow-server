<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E8.1 (issue 22): "wyłącznie Moderator/Admin" — this file's whole job is
 * proving that gate holds (participant and organizer rejected, moderator and
 * admin both succeed) and that the three endpoints behave over real HTTP,
 * not just at the service layer (see CompetitorMergeServiceTest for the
 * deeper merge-flow coverage).
 */
final class CompetitorMergeContractTest extends HttpTestCase
{
    private CompetitorRepository $competitors;
    private string $prefix;
    /** @var array<string, array<string, mixed>> role => real user row */
    private array $actors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->prefix = 'HttpMerge-' . bin2hex(random_bytes(4));
        // PP.7: the session guard rejects tokens of users that do not exist, so each role is a real account.
        $users = new UserRepository($this->pdo);
        foreach (['participant', 'organizer', 'moderator', 'admin'] as $role) {
            $this->actors[$role] = $users->create([
                'name' => 'Merge ' . $role,
                'email' => strtolower($this->prefix) . '-' . $role . '@example.test',
                'password' => 'demo1234',
                'role' => $role,
            ]);
        }
    }

    private function actor(string $role): array
    {
        return $this->bearer((string) $this->actors[$role]['id'], $role, (string) $this->actors[$role]['email']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM competitors WHERE last_name = :last');
        $stmt->execute(['last' => $this->prefix]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM identity_code_aliases WHERE competitor_id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM activity_logs WHERE target_type = "competitor" AND target_id IN (SELECT id FROM competitors WHERE last_name = :last)')
            ->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')->execute(['last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => strtolower($this->prefix) . '-%@example.test']);
    }

    public function testParticipantCannotSearchCompareOrMerge(): void
    {
        [$survivor, $absorbed] = $this->twoCompetitors();
        $headers = $this->actor('participant');

        self::assertSame(403, $this->request('GET', '/api/v1/admin/competitors/search?q=' . $this->prefix, [], $headers)->getStatusCode());
        self::assertSame(
            403,
            $this->request('GET', "/api/v1/admin/competitors/{$survivor['id']}/merge-comparison/{$absorbed['id']}", [], $headers)->getStatusCode()
        );
        self::assertSame(
            403,
            $this->request(
                'POST',
                '/api/v1/admin/competitors/merge',
                ['survivorId' => $survivor['id'], 'absorbedId' => $absorbed['id']],
                $headers
            )->getStatusCode()
        );
    }

    public function testOrganizerCannotMergeEvenThoughItIsAStaffAdjacentRole(): void
    {
        [$survivor, $absorbed] = $this->twoCompetitors();

        $response = $this->request(
            'POST',
            '/api/v1/admin/competitors/merge',
            ['survivorId' => $survivor['id'], 'absorbedId' => $absorbed['id']],
            $this->actor('organizer')
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testModeratorCanSearchCompareAndMerge(): void
    {
        [$survivor, $absorbed] = $this->twoCompetitors();
        $headers = $this->actor('moderator');

        $search = $this->request('GET', '/api/v1/admin/competitors/search?q=' . $this->prefix, [], $headers);
        self::assertSame(200, $search->getStatusCode());
        self::assertCount(2, $this->json($search)['data']);

        $comparison = $this->request('GET', "/api/v1/admin/competitors/{$survivor['id']}/merge-comparison/{$absorbed['id']}", [], $headers);
        self::assertSame(200, $comparison->getStatusCode());
        $comparisonData = $this->json($comparison)['data'];
        self::assertCount(2, $comparisonData['competitors']);

        $merge = $this->request(
            'POST',
            '/api/v1/admin/competitors/merge',
            ['survivorId' => $survivor['id'], 'absorbedId' => $absorbed['id'], 'fieldDecisions' => []],
            $headers
        );
        self::assertSame(200, $merge->getStatusCode());
        self::assertSame('merged', $this->json($merge)['data']['status']);
    }

    /**
     * The search query once used one repeated named placeholder, which native prepares
     * reject (HTTP 500) — for every role, not only moderators.
     */
    public function testAdminCanSearch(): void
    {
        $this->twoCompetitors();

        $search = $this->request('GET', '/api/v1/admin/competitors/search?q=' . $this->prefix, [], $this->actor('admin'));

        self::assertSame(200, $search->getStatusCode());
        self::assertCount(2, $this->json($search)['data']);
    }

    public function testAdminCanMerge(): void
    {
        [$survivor, $absorbed] = $this->twoCompetitors();

        $merge = $this->request(
            'POST',
            '/api/v1/admin/competitors/merge',
            ['survivorId' => $survivor['id'], 'absorbedId' => $absorbed['id'], 'fieldDecisions' => []],
            $this->actor('admin')
        );

        self::assertSame(200, $merge->getStatusCode());
    }

    public function testMergeRespondsWithFieldDecisionsRequiredWhenProfilesDiffer(): void
    {
        $survivor = $this->competitors->create(['firstName' => 'Http', 'lastName' => $this->prefix, 'birthDate' => '2010-01-01']);
        $absorbed = $this->competitors->create(['firstName' => 'Http', 'lastName' => $this->prefix, 'birthDate' => '2011-02-02']);

        $response = $this->request(
            'POST',
            '/api/v1/admin/competitors/merge',
            ['survivorId' => $survivor['id'], 'absorbedId' => $absorbed['id']],
            $this->actor('admin')
        );

        // Deliberately 200: a pending field decision is a normal pause in this
        // multi-step flow, not an HTTP error — see routes.php's own comment on
        // this endpoint for why (mirrors POST /care's duplicateWarnings idiom).
        self::assertSame(200, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertSame('field_decisions_required', $payload['data']['status']);
        self::assertContains('birthDate', $payload['data']['fields']);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function twoCompetitors(): array
    {
        $survivor = $this->competitors->create(['firstName' => 'Http', 'lastName' => $this->prefix]);
        $absorbed = $this->competitors->create(['firstName' => 'Http', 'lastName' => $this->prefix]);
        return [$survivor, $absorbed];
    }
}
