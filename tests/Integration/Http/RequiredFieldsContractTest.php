<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PP.76: POST/PATCH /challenges and /info-pages with missing or wrongly typed fields answer
 * 422 validation_error (details.field) instead of a 500 from strict MySQL. The test session
 * runs with MySQL 8.4's sql_mode (see StrictSessionTest), so the "fails before" behaviour is
 * the production one even on a non-strict local MariaDB.
 */
final class RequiredFieldsContractTest extends HttpTestCase
{
    private string $prefix;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'http-req-' . bin2hex(random_bytes(4));
        $this->admin = (new UserRepository($this->pdo))->create([
            'name' => 'Required Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        // challenge_events / challenge_products / condition-tree tables cascade from challenges.
        $this->pdo->prepare('DELETE FROM challenges WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM info_pages WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '%']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        return $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
    }

    private function assertValidationError(ResponseInterface $response, string $field): array
    {
        $payload = $this->json($response);

        self::assertSame(422, $response->getStatusCode(), json_encode($payload));
        self::assertFalse($payload['success']);
        self::assertSame('validation_error', $payload['error']['code']);
        self::assertSame($field, $payload['error']['details']['field']);
        self::assertStringStartsWith('Pole „', $payload['error']['message']);

        return $payload;
    }

    private function countRows(string $table, string $column = 'slug'): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE :p");
        $stmt->execute(['p' => $this->prefix . '%']);

        return (int) $stmt->fetchColumn();
    }

    private function createChallenge(): string
    {
        $response = $this->request('POST', '/api/v1/challenges', [
            'name' => $this->prefix . ' Challenge',
            'slug' => $this->prefix . '-challenge',
        ], $this->adminHeaders());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['data']['id'];
    }

    private function createInfoPage(): string
    {
        $response = $this->request('POST', '/api/v1/info-pages', [
            'title' => $this->prefix . ' Page',
            'slug' => $this->prefix . '-page',
        ], $this->adminHeaders());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['data']['id'];
    }

    /** Was 1364 (name has no default) -> 500 on strict MySQL. */
    public function testCreateChallengeWithEmptyBodyIs422(): void
    {
        $response = $this->request('POST', '/api/v1/challenges', [], $this->adminHeaders());

        $payload = $this->assertValidationError($response, 'name');
        self::assertSame('Pole „Nazwa wyzwania” jest wymagane.', $payload['error']['message']);
        self::assertSame(0, $this->countRows('challenges'));
    }

    public function testCreateChallengeWithoutSlugIs422(): void
    {
        $response = $this->request('POST', '/api/v1/challenges', ['name' => $this->prefix . ' Challenge'], $this->adminHeaders());

        $this->assertValidationError($response, 'slug');
        self::assertSame(0, $this->countRows('challenges'));
    }

    /** Was 1364 (description has no default) -> 500 on strict MySQL. */
    public function testCreateChallengeWithNameAndSlugOnlySucceeds(): void
    {
        $response = $this->request('POST', '/api/v1/challenges', [
            'name' => $this->prefix . ' Challenge',
            'slug' => $this->prefix . '-challenge',
        ], $this->adminHeaders());
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode(), json_encode($payload));
        self::assertSame('', $payload['data']['description']);
    }

    /** Was a TypeError in replaceEvents() -> 500. */
    public function testPatchChallengeRejectsNonListEvents(): void
    {
        $id = $this->createChallenge();

        $response = $this->request('PATCH', '/api/v1/challenges/' . $id, ['events' => 'x'], $this->adminHeaders());

        $this->assertValidationError($response, 'events');
    }

    /** Was 1048 (title NOT NULL) -> 500 on strict MySQL. */
    public function testCreateInfoPageWithEmptyBodyIs422(): void
    {
        $response = $this->request('POST', '/api/v1/info-pages', [], $this->adminHeaders());

        $this->assertValidationError($response, 'title');
        self::assertSame(0, $this->countRows('info_pages'));
    }

    /** Was 1048/1364 (icon, description) -> 500 on strict MySQL. */
    public function testCreateInfoPageWithTitleAndSlugOnlyDefaultsIconAndDescription(): void
    {
        $response = $this->request('POST', '/api/v1/info-pages', [
            'title' => $this->prefix . ' Page',
            'slug' => $this->prefix . '-page',
        ], $this->adminHeaders());
        $payload = $this->json($response);

        self::assertSame(201, $response->getStatusCode(), json_encode($payload));
        self::assertSame('FileText', $payload['data']['icon']);
        self::assertSame('', $payload['data']['description']);
    }

    /** Both were 1048 (NOT NULL) -> 500 on strict MySQL. */
    public function testPatchInfoPageRejectsNullOrderAndNullPublished(): void
    {
        $id = $this->createInfoPage();

        $this->assertValidationError(
            $this->request('PATCH', '/api/v1/info-pages/' . $id, ['order' => null], $this->adminHeaders()),
            'order'
        );
        $this->assertValidationError(
            $this->request('PATCH', '/api/v1/info-pages/' . $id, ['published' => null], $this->adminHeaders()),
            'published'
        );
    }

    /**
     * Regression guard (passes before and after PP.76, not a red test): the handler's UrlPolicy
     * check runs before the repository, so an unsafe URL still wins over the missing slug.
     */
    public function testChallengeUnsafeUrlStillWinsOverMissingSlug(): void
    {
        $response = $this->request('POST', '/api/v1/challenges', [
            'name' => 'C',
            'imageUrl' => 'javascript:alert(1)',
        ], $this->adminHeaders());
        $payload = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_url', $payload['error']['code']);
    }
}
