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
        // last_name is the random per-test prefix for every standalone ward this file
        // creates (Zosia in the E0.1 tests, plus E1.12's Rotacja/Cudzy wards) — matching
        // on it alone (not also first_name) catches all of them without over-deleting.
        $this->pdo->prepare('DELETE FROM competitors WHERE last_name = :last')
            ->execute(['last' => $this->prefix]);
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

    /**
     * E1.12: rotacja na żądanie — Konto sprawujące Opiekę nad Zawodnikiem (tu:
     * parent1 dodał dziecko pod swoją opiekę) może wygenerować mu nowy kod
     * tożsamości, unieważniając stary od razu (nadpisanie, bez historii).
     */
    public function testGuardianCanRotateWardsIdentityCode(): void
    {
        $add = $this->json($this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Rotacja', 'lastName' => $this->prefix, 'birthDate' => '2016-01-01'],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        ))['data'];
        $competitorId = $add['competitor']['id'];
        $oldCode = $add['competitor']['identityCode'];
        self::assertMatchesRegularExpression('/^zqr_[a-f0-9]{48}$/', (string) $oldCode);

        $rotate = $this->request(
            'POST',
            '/api/v1/competitors/' . $competitorId . '/rotate-identity-code',
            [],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        );

        self::assertSame(200, $rotate->getStatusCode());
        $rotated = $this->json($rotate)['data'];
        self::assertMatchesRegularExpression('/^zqr_[a-f0-9]{48}$/', (string) $rotated['identityCode']);
        self::assertNotSame($oldCode, $rotated['identityCode']);
    }

    /** A Konto with no Opieka over the Zawodnik (and not the Zawodnik itself) may not rotate their code. */
    public function testStrangerCannotRotateSomeoneElsesIdentityCode(): void
    {
        $add = $this->json($this->request(
            'POST',
            '/api/v1/care',
            ['firstName' => 'Cudzy', 'lastName' => $this->prefix, 'birthDate' => '2016-01-01'],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        ))['data'];
        $competitorId = $add['competitor']['id'];

        $forbidden = $this->request(
            'POST',
            '/api/v1/competitors/' . $competitorId . '/rotate-identity-code',
            [],
            $this->bearer($this->parent2['id'], 'participant', $this->parent2['email'])
        );

        self::assertSame(403, $forbidden->getStatusCode());
    }

    /** The Zawodnik itself (own Konto, own profile) can rotate its own identity code too, no Opieka needed. */
    public function testCompetitorWithOwnAccountCanRotateOwnIdentityCode(): void
    {
        $care = $this->request(
            'GET',
            '/api/v1/care',
            [],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        );
        $myProfile = $this->json($care)['data']['myProfile'];
        self::assertNotNull($myProfile);
        self::assertMatchesRegularExpression('/^zqr_[a-f0-9]{48}$/', (string) $myProfile['identityCode']);

        $rotate = $this->request(
            'POST',
            '/api/v1/competitors/' . $myProfile['id'] . '/rotate-identity-code',
            [],
            $this->bearer($this->parent1['id'], 'participant', $this->parent1['email'])
        );

        self::assertSame(200, $rotate->getStatusCode());
        self::assertNotSame($myProfile['identityCode'], $this->json($rotate)['data']['identityCode']);
    }
}
