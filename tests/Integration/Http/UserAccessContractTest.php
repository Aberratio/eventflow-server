<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class UserAccessContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    private array $participant;
    private array $participant2;
    private array $moderator;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-ua-' . bin2hex(random_bytes(4));

        $this->participant = $this->users->create([
            'name' => 'P One',
            'email' => $this->prefix . '-p@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->participant2 = $this->users->create([
            'name' => 'Q Two',
            'email' => $this->prefix . '-q@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $this->moderator = $this->users->create([
            'name' => 'M Mod',
            'email' => $this->prefix . '-m@example.test',
            'password' => 'demo1234',
            'role' => 'moderator',
        ]);
        $this->admin = $this->users->create([
            'name' => 'A Admin',
            'email' => $this->prefix . '-a@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM central_mailer_outbox WHERE recipient LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testAnonymousCannotReadAProfile(): void
    {
        $response = $this->request('GET', '/api/v1/users/' . $this->participant['id']);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testParticipantCannotReadAnotherProfile(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/users/' . $this->participant['id'],
            [],
            $this->bearer($this->participant2['id'], 'participant', $this->participant2['email'])
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString((string) $this->participant['email'], (string) $response->getBody());
    }

    public function testSelfAndStaffCanReadFullProfile(): void
    {
        foreach ([
            [$this->participant['id'], 'participant', $this->participant['email']],
            [$this->moderator['id'], 'moderator', $this->moderator['email']],
            [$this->admin['id'], 'admin', $this->admin['email']],
        ] as [$callerId, $role, $callerEmail]) {
            $response = $this->request(
                'GET',
                '/api/v1/users/' . $this->participant['id'],
                [],
                $this->bearer($callerId, $role, $callerEmail)
            );
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($this->participant['email'], $this->json($response)['data']['email']);
        }
    }

    public function testParticipantCannotEscalateOwnRole(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant['id'],
            ['role' => 'organizer'],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('field_not_editable', $this->json($response)['error']['code']);

        $role = $this->pdo->prepare('SELECT role FROM users WHERE id = :id');
        $role->execute(['id' => $this->participant['id']]);
        self::assertSame('participant', $role->fetchColumn());
    }

    public function testParticipantCannotEditAnotherAccount(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant['id'],
            ['name' => 'Hacked'],
            $this->bearer($this->participant2['id'], 'participant', $this->participant2['email'])
        );
        self::assertSame(403, $response->getStatusCode());

        $name = $this->pdo->prepare('SELECT name FROM users WHERE id = :id');
        $name->execute(['id' => $this->participant['id']]);
        self::assertSame('P One', $name->fetchColumn());
    }

    public function testModeratorCannotEditAnotherAccount(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant['id'],
            ['name' => 'Hacked'],
            $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email'])
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testSelfProfileFormPayloadIsAccepted(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant['id'],
            [
                'name' => 'New Name',
                'phone' => '123456789',
                'address' => ['city' => 'Warszawa'],
                'birthDate' => '1990-01-01',
                'birthYear' => 1990,
                'gender' => 'male',
                'club' => 'Klub',
                'sosContacts' => [['name' => 'X', 'phone' => '1']],
                'companyName' => 'Firma',
                'nip' => '1234567890',
                'website' => 'https://example.test',
                'description' => 'Opis',
                'email' => $this->participant['email'],
            ],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('New Name', $data['name']);
        self::assertSame('Firma', $data['companyName']);
    }

    public function testSelfEmailChangeIsRefused(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant['id'],
            ['email' => 'someone-else@example.test'],
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('email_change_not_allowed', $this->json($response)['error']['code']);
    }

    public function testAdminCanChangeEmailButNotRoleViaPatch(): void
    {
        $newEmail = $this->prefix . '-p-new@example.test';
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant['id'],
            ['email' => $newEmail],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($newEmail, $this->json($response)['data']['email']);

        $taken = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant2['id'],
            ['email' => $newEmail],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(409, $taken->getStatusCode());

        $roleResp = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->participant['id'],
            ['role' => 'organizer'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(422, $roleResp->getStatusCode());
    }

    public function testAdminCanEditOwnProfile(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/users/' . $this->admin['id'],
            ['name' => 'Admin Renamed'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Admin Renamed', $this->json($response)['data']['name']);
    }

    public function testParticipantAndOrganizerListsRequireStaff(): void
    {
        foreach (['/api/v1/participants', '/api/v1/organizers'] as $path) {
            $anon = $this->request('GET', $path);
            self::assertSame(401, $anon->getStatusCode(), $path);

            $participantResp = $this->request('GET', $path, [], $this->bearer($this->participant['id'], 'participant', $this->participant['email']));
            self::assertSame(403, $participantResp->getStatusCode(), $path);

            $modResp = $this->request('GET', $path, [], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']));
            self::assertSame(200, $modResp->getStatusCode(), $path);
        }
    }

    public function testRegisterIgnoresRoleStatusAndEventLimit(): void
    {
        $email = $this->prefix . '-evil@example.test';
        $response = $this->request('POST', '/api/v1/auth/register', [
            'name' => 'Evil',
            'email' => $email,
            'password' => 'demo1234',
            'role' => 'superadmin',
            'status' => 'active',
            'eventLimit' => 99,
        ]);

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('participant', $data['role']);
        self::assertSame(0, (int) $data['eventLimit']);

        $row = $this->pdo->prepare('SELECT role, event_limit FROM users WHERE email = :email');
        $row->execute(['email' => $email]);
        $fetched = $row->fetch();
        self::assertSame('participant', $fetched['role']);
        self::assertSame(0, (int) $fetched['event_limit']);
    }
}
