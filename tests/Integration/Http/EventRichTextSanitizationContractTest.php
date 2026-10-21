<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * Server-side sanitization (second line of defence — the client also
 * sanitizes on every render, so already-stored rows stay safe regardless).
 * Confirms rich-text columns are cleaned on write for every write path listed
 * in the plan: events.description/rules_html/organizers_html/
 * final_acceptance_html, events.logistics_info.*.infoHtml,
 * event_distances.info_html, event_merchandise.description_html.
 */
final class EventRichTextSanitizationContractTest extends HttpTestCase
{
    private UserRepository $users;
    private string $prefix;
    private array $organizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-xss-' . bin2hex(random_bytes(4));

        // Not calling Migrator::applyAll() here: on this lane DB, migration
        // 052_e5_5_invoice_data.sql is not idempotent (plain `ADD COLUMN`, no
        // `IF NOT EXISTS`/information_schema guard — a pre-existing issue owned by
        // another lane) and fails on a second run. Migrations were already
        // applied once for this DB before running these tests.
        $this->organizer = $this->users->create([
            'name' => 'XSS Test Organizer',
            'email' => $this->prefix . '-org@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_merchandise WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_gallery_albums WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testCreateEventSanitizesRichTextFields(): void
    {
        $slug = $this->prefix . '-create';
        $payload = $this->eventPayload([
            'slug' => $slug,
            'description' => '<p>ok</p><img src=x onerror=alert(1)><script>alert(1)</script>',
            'rulesHtml' => '<p><strong>Regulamin</strong></p><svg onload=alert(1)></svg>',
            'organizersHtml' => '<p>Organizator</p><a href="javascript:alert(1)">k</a>',
            'finalAcceptanceHtml' => '<p>Akceptacja</p><img src=x onerror=alert(1)>',
            'logisticsInfo' => [
                'parking' => ['status' => 'yes', 'infoHtml' => '<p>Parking</p><img src=x onerror=alert(1)>'],
            ],
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
                    'infoHtml' => '<p>Dystans</p><svg onload=alert(1)></svg>',
                ],
            ],
            'merchandise' => [
                [
                    'name' => 'Koszulka',
                    'price' => 50,
                    'descriptionHtml' => '<p>Opis</p><script>alert(1)</script>',
                ],
            ],
        ]);

        $response = $this->request(
            'POST',
            '/api/v1/events',
            $payload,
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $body = $this->json($response);
        self::assertSame(201, $response->getStatusCode(), (string) json_encode($body));
        $eventId = (string) $body['data']['id'];

        $stmt = $this->pdo->prepare(
            'SELECT description, rules_html, organizers_html, final_acceptance_html, logistics_info '
                . 'FROM events WHERE id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();

        foreach (['description', 'rules_html', 'organizers_html', 'final_acceptance_html'] as $column) {
            self::assertStringNotContainsStringIgnoringCase('<script', (string) $row[$column], $column);
            self::assertStringNotContainsStringIgnoringCase('<img', (string) $row[$column], $column);
            self::assertStringNotContainsStringIgnoringCase('<svg', (string) $row[$column], $column);
            self::assertStringNotContainsStringIgnoringCase('onerror', (string) $row[$column], $column);
            self::assertStringNotContainsStringIgnoringCase('javascript:', (string) $row[$column], $column);
        }
        self::assertStringContainsString('<p>ok</p>', (string) $row['description']);
        self::assertStringContainsString('<strong>Regulamin</strong>', (string) $row['rules_html']);

        $logisticsInfo = json_decode((string) $row['logistics_info'], true);
        self::assertStringNotContainsStringIgnoringCase('<img', (string) ($logisticsInfo['parking']['infoHtml'] ?? ''));
        self::assertStringContainsString('<p>Parking</p>', (string) ($logisticsInfo['parking']['infoHtml'] ?? ''));

        $distanceStmt = $this->pdo->prepare('SELECT info_html FROM event_distances WHERE event_id = :id');
        $distanceStmt->execute(['id' => $eventId]);
        $distanceInfoHtml = (string) $distanceStmt->fetchColumn();
        self::assertStringNotContainsStringIgnoringCase('<svg', $distanceInfoHtml);
        self::assertStringNotContainsStringIgnoringCase('onload', $distanceInfoHtml);
        self::assertStringContainsString('<p>Dystans</p>', $distanceInfoHtml);

        $merchandiseStmt = $this->pdo->prepare('SELECT description_html FROM event_merchandise WHERE event_id = :id');
        $merchandiseStmt->execute(['id' => $eventId]);
        $merchandiseHtml = (string) $merchandiseStmt->fetchColumn();
        self::assertStringNotContainsStringIgnoringCase('<script', $merchandiseHtml);
        self::assertStringContainsString('<p>Opis</p>', $merchandiseHtml);
    }

    public function testPatchEventSanitizesDescriptionAndLogistics(): void
    {
        $slug = $this->prefix . '-patch';
        $created = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $slug, 'description' => '<p>clean</p>']),
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $eventId = (string) $this->json($created)['data']['id'];

        $patchResponse = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            [
                'description' => '<p>ok</p><img src=x onerror=alert(1)>',
                'logisticsInfo' => [
                    'raceOffice' => ['status' => 'yes', 'infoHtml' => '<a href="javascript:alert(1)">x</a>'],
                ],
            ],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $patchResponse->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT description, logistics_info FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();

        self::assertSame('<p>ok</p>', (string) $row['description']);
        $logisticsInfo = json_decode((string) $row['logistics_info'], true);
        self::assertStringNotContainsStringIgnoringCase(
            'javascript:',
            (string) ($logisticsInfo['raceOffice']['infoHtml'] ?? ''),
        );

        $getResponse = $this->request(
            'GET',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $getBody = $this->json($getResponse);
        self::assertSame('<p>ok</p>', $getBody['data']['description']);
    }

    public function testPatchKeepsLegitTipTapLink(): void
    {
        $slug = $this->prefix . '-legit';
        $created = $this->request(
            'POST',
            '/api/v1/events',
            $this->eventPayload(['slug' => $slug]),
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $eventId = (string) $this->json($created)['data']['id'];

        $tiptapLink = '<p><a target="_blank" rel="noopener noreferrer nofollow" href="https://example.pl">link</a></p>';

        $patchResponse = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['description' => $tiptapLink],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        self::assertSame(200, $patchResponse->getStatusCode());

        $getResponse = $this->request(
            'GET',
            '/api/v1/events/' . $eventId,
            [],
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
        $description = (string) $this->json($getResponse)['data']['description'];

        self::assertStringContainsString('href="https://example.pl"', $description);
        self::assertStringContainsString('target="_blank"', $description);
        self::assertStringContainsString('noopener', $description);
    }

    private function eventPayload(array $overrides = []): array
    {
        $slug = (string) ($overrides['slug'] ?? $this->prefix . '-default');

        return array_replace_recursive([
            'name' => 'PHPUnit XSS Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'XSS Test Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
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
        ], $overrides);
    }
}
