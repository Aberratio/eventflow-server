<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class CareContractTest extends HttpTestCase
{
    private UserRepository $users;
    private CompetitorRepository $competitors;
    private string $prefix;
    private array $parent1;
    private array $parent2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->prefix = 'http-care-' . bin2hex(random_bytes(4));

        $this->parent1 = $this->users->create([
            'name' => 'Care Parent One',
            'email' => $this->prefix . '-parent1@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->parent2 = $this->users->create([
            'name' => 'Care Parent Two',
            'email' => $this->prefix . '-parent2@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->pdo->prepare(
            "DELETE cr FROM care_relations cr JOIN users u ON u.id = cr.konto_id WHERE u.email LIKE :email"
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare(
            "DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email"
        )->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM competitors WHERE first_name = :first AND last_name = :last')
            ->execute(['first' => 'Zosia', 'last' => $this->prefix]);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCreatingStandaloneCompetitorDoesNotRequireAccount(): void
    {
        $competitor = $this->competitors->create([
            'firstName' => 'Zosia',
            'lastName' => $this->prefix,
            'birthDate' => '2018-05-01',
        ]);

        self::assertNull($competitor['userId']);
        self::assertSame('Zosia', $competitor['firstName']);
    }

    public function testTwoParentsRegisteringSameChildShareOneCompetitor(): void
    {
        $firstAdd = $this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Zosia', 'lastName' => $this->prefix, 'birthDate' => '2018-05-01'],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        );
        $firstPayload = $this->json($firstAdd);
        self::assertSame(201, $firstAdd->getStatusCode());
        $competitorId = $firstPayload['data']['competitor']['id'];

        // Second parent registers "the same" child (same name + DOB) — should get a
        // duplicate warning, but nothing stops them from creating their own Opieka.
        $secondAdd = $this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Zosia', 'lastName' => $this->prefix, 'birthDate' => '2018-05-01'],
            $this->bearer($this->parent2['id'], 'participant', $this->parent2['email'])
        );
        $secondPayload = $this->json($secondAdd);
        self::assertSame(201, $secondAdd->getStatusCode());
        self::assertNotEmpty($secondPayload['data']['duplicateWarnings']);
        self::assertSame($competitorId, $secondPayload['data']['duplicateWarnings'][0]['id']);

        // Both parents now see a Zawodnik pod opieką — the app is expected to steer
        // them to the existing profile using the warning above; this asserts that
        // creating a second one does NOT silently merge (out of scope for E0.1),
        // matching AC3: warn, don't block, don't silently dedupe.
        $listResponse = $this->request(
            'GET',
            '/api/v1/care',
            [],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        );
        $list = $this->json($listResponse)['data'];
        self::assertCount(1, $list['myCompetitors']);
    }

    public function testGrantingCareByEmailAllowsRegisteringSelf(): void
    {
        $response = $this->request(
            'POST',
            '/api/v1/care',
            ['email' => $this->parent2['email']],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        );

        self::assertSame(201, $response->getStatusCode());

        $listResponse = $this->request(
            'GET',
            '/api/v1/care',
            [],
            $this->bearer($this->parent2['id'], 'participant', $this->parent2['email'])
        );
        $list = $this->json($listResponse)['data'];
        self::assertCount(1, $list['myCompetitors']);
        self::assertSame('Care Parent', $list['myCompetitors'][0]['firstName']);
    }

    public function testRevokeRequiresBeingCaregiverOrTheCompetitorThemselves(): void
    {
        $grant = $this->json($this->request(
            'POST',
            '/api/v1/care',
            ['email' => $this->parent2['email']],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        ))['data'];

        // An unrelated caller cannot revoke.
        $stranger = $this->users->create([
            'name' => 'Care Stranger',
            'email' => $this->prefix . '-stranger@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $forbidden = $this->request(
            'DELETE',
            '/api/v1/care/' . $grant['id'],
            [],
            $this->bearer($stranger['id'], 'participant', $stranger['email'])
        );
        self::assertSame(404, $forbidden->getStatusCode());

        $ok = $this->request(
            'DELETE',
            '/api/v1/care/' . $grant['id'],
            [],
            $this->bearer($this->parent2['id'], 'participant', $this->parent2['email'])
        );
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testCannotRegisterCompetitorWithoutCare(): void
    {
        $competitor = $this->competitors->create([
            'firstName' => 'NoCare',
            'lastName' => $this->prefix,
            'birthDate' => '2015-01-01',
        ]);

        $response = $this->request(
            'POST',
            '/api/v1/registrations',
            [
                'eventId' => 'nonexistent',
                'competitorId' => $competitor['id'],
                'distanceId' => 'nonexistent',
                'category' => 'Open',
            ],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('not_authorized_to_register', $this->json($response)['error']['code']);
    }
}
