<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

final class SeriesAndPartnersContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    private array $admin;
    private array $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-spar-' . bin2hex(random_bytes(4));

        $this->admin = $this->users->create([
            'name' => 'SP Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
        $this->participant = $this->users->create([
            'name' => 'SP Participant',
            'email' => $this->prefix . '-part@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM series WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE FROM partners WHERE name LIKE :name')->execute(['name' => $this->prefix . '%']);
    }

    // ── Series ───────────────────────────────────────────────────────────────

    public function testSeriesListIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/series');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('total', $payload);
    }

    public function testCreateSeriesRequiresStaffRole(): void
    {
        $noAuth = $this->request('POST', '/api/v1/series', $this->seriesPayload());
        self::assertSame(401, $noAuth->getStatusCode());

        $participantResp = $this->request(
            'POST',
            '/api/v1/series',
            $this->seriesPayload(),
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $participantResp->getStatusCode());
    }

    public function testAdminCanCreateAndFetchSeries(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/series',
            $this->seriesPayload(),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $created = $this->json($createResp);

        self::assertSame(201, $createResp->getStatusCode());
        self::assertTrue($created['success']);
        self::assertSame($this->prefix . '-series', $created['data']['slug']);

        $seriesId = $created['data']['id'];

        $getResp = $this->request('GET', '/api/v1/series/' . $seriesId);
        self::assertSame(200, $getResp->getStatusCode());
        self::assertSame($seriesId, $this->json($getResp)['data']['id']);

        $slugResp = $this->request('GET', '/api/v1/series/slug/' . $this->prefix . '-series');
        self::assertSame(200, $slugResp->getStatusCode());
        self::assertSame($seriesId, $this->json($slugResp)['data']['id']);
    }

    public function testAdminCanUpdateSeries(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/series',
            $this->seriesPayload(),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $seriesId = $this->json($createResp)['data']['id'];

        $patchResp = $this->request(
            'PATCH',
            '/api/v1/series/' . $seriesId,
            ['name' => $this->prefix . ' Series Updated'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $updated = $this->json($patchResp);

        self::assertSame(200, $patchResp->getStatusCode());
        self::assertSame($this->prefix . ' Series Updated', $updated['data']['name']);
    }

    public function testAdminCanArchiveSeries(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/series',
            $this->seriesPayload(),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $seriesId = $this->json($createResp)['data']['id'];

        $archiveResp = $this->request(
            'POST',
            '/api/v1/series/' . $seriesId . '/archive',
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );

        self::assertSame(200, $archiveResp->getStatusCode());
        self::assertTrue($this->json($archiveResp)['success']);
    }

    // ── Partners ─────────────────────────────────────────────────────────────

    public function testPartnersListIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/partners');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    public function testCreatePartnerRequiresAdminRole(): void
    {
        $noAuth = $this->request('POST', '/api/v1/partners', $this->partnerPayload());
        self::assertSame(401, $noAuth->getStatusCode());

        $participantResp = $this->request(
            'POST',
            '/api/v1/partners',
            $this->partnerPayload(),
            $this->bearer($this->participant['id'], 'participant', $this->participant['email'])
        );
        self::assertSame(403, $participantResp->getStatusCode());
    }

    public function testCreatePartnerValidationRequiresNameAndLogo(): void
    {
        $noName = $this->request(
            'POST',
            '/api/v1/partners',
            ['name' => '', 'logoUrl' => 'https://example.test/logo.png'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(422, $noName->getStatusCode());
        self::assertSame('validation_error', $this->json($noName)['error']['code']);

        $noLogo = $this->request(
            'POST',
            '/api/v1/partners',
            ['name' => $this->prefix . ' Partner', 'logoUrl' => ''],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(422, $noLogo->getStatusCode());
    }

    public function testAdminCanCreateUpdateAndDeletePartner(): void
    {
        $createResp = $this->request(
            'POST',
            '/api/v1/partners',
            $this->partnerPayload(),
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        $created = $this->json($createResp);

        self::assertSame(201, $createResp->getStatusCode());
        self::assertTrue($created['success']);
        $partnerId = $created['data']['id'];

        $patchResp = $this->request(
            'PATCH',
            '/api/v1/partners/' . $partnerId,
            ['name' => $this->prefix . ' Partner Updated'],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $patchResp->getStatusCode());
        self::assertSame($this->prefix . ' Partner Updated', $this->json($patchResp)['data']['name']);

        $deleteResp = $this->request(
            'DELETE',
            '/api/v1/partners/' . $partnerId,
            [],
            $this->bearer($this->admin['id'], 'admin', $this->admin['email'])
        );
        self::assertSame(200, $deleteResp->getStatusCode());

        $getResp = $this->request('GET', '/api/v1/partners/' . $partnerId);
        $getPayload = $this->json($getResp);
        self::assertNull($getPayload['data']);
    }

    public function testFeaturedPartnersEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/v1/partners/featured');
        $payload = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
    }

    private function seriesPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->prefix . ' Series',
            'slug' => $this->prefix . '-series',
            'discipline' => 'running',
            'description' => '<p>Opis serii testowej</p>',
            'imageUrl' => 'https://example.test/series.jpg',
        ], $overrides);
    }

    private function partnerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->prefix . ' Partner',
            'logoUrl' => 'https://example.test/logo.png',
            'websiteUrl' => 'https://example.test',
            'featured' => false,
        ], $overrides);
    }
}
