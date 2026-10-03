<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PP.50: every stored URL field reachable through the API rejects non-http(s) schemes on write
 * (422 invalid_url, Polish message, field = concrete path) and leaves the row untouched.
 */
final class UrlFieldValidationContractTest extends HttpTestCase
{
    private const JS = 'javascript:window.__xss=1';

    private UserRepository $users;
    private string $prefix;
    private array $organizer;
    private array $otherOrganizer;
    private array $participant;
    private array $admin;
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-url-' . bin2hex(random_bytes(4));

        // No Migrator::applyAll(): migration 052 is not idempotent on an already migrated DB (PP.13).
        $make = fn (string $suffix, string $role, array $extra = []): array => $this->users->create(array_merge([
            'name' => 'URL Test ' . $suffix,
            'email' => $this->prefix . '-' . $suffix . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ], $extra));
        $this->organizer = $make('org', 'organizer', ['eventLimit' => 5]);
        $this->otherOrganizer = $make('org2', 'organizer', ['eventLimit' => 5]);
        $this->participant = $make('part', 'participant');
        $this->admin = $make('admin', 'admin');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            foreach (['event_distances', 'event_merchandise', 'event_discount_codes', 'event_payment_methods',
                'event_gallery_albums', 'event_documents', 'event_result_documents', 'registrations'] as $table) {
                $this->pdo->prepare("DELETE FROM $table WHERE event_id = :id")->execute(['id' => $id]);
            }
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->cleanup as [$table, $column, $value]) {
            $this->pdo->prepare("DELETE FROM $table WHERE $column = :v")->execute(['v' => $value]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
    }

    // ---- events ----------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function unsafeEmbedUrlProvider(): array
    {
        return [
            'javascript' => ['javascript:window.__xss=1'],
            'data' => ['data:text/html,<script>x</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'leading space' => [' javascript:alert(1)'],
            'tab inside' => ["java\tscript:alert(1)"],
            'relative' => ['/relative'],
            'mailto' => ['mailto:x@y.pl'],
            'no slashes' => ['https:evil.com'],
        ];
    }

    #[DataProvider('unsafeEmbedUrlProvider')]
    public function testPatchEventRejectsUnsafeExternalRegistrationUrl(string $url): void
    {
        $eventId = $this->createEvent('embed');
        $before = $this->rawColumn($eventId, 'registration_embed');

        $response = $this->patchEvent($eventId, [
            'registrationEmbed' => ['enabled' => true, 'mode' => 'external_embed', 'externalUrl' => $url],
        ]);

        $this->assertInvalidUrl($response, 'registrationEmbed.externalUrl', 'Adres zewnętrznego formularza zapisów');
        self::assertSame($before, $this->rawColumn($eventId, 'registration_embed'));
    }

    public function testPatchEventAcceptsAndNormalizesHttpsUrls(): void
    {
        $eventId = $this->createEvent('ok');

        $response = $this->patchEvent($eventId, [
            'registrationEmbed' => ['enabled' => true, 'mode' => 'external_embed', 'externalUrl' => 'https://example.com/form'],
            'organizerContact' => ['website' => 'www.example.pl'],
            'documents' => [['id' => $this->prefix . '-doc', 'name' => 'Regulamin', 'type' => 'regulamin', 'fileUrl' => '/uploads/event-docs/a.pdf', 'fileName' => 'a.pdf']],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $embed = json_decode((string) $this->rawColumn($eventId, 'registration_embed'), true);
        self::assertSame('https://example.com/form', $embed['externalUrl']);
        $contact = json_decode((string) $this->rawColumn($eventId, 'organizer_contact'), true);
        self::assertSame('https://www.example.pl', $contact['website']);
        $stmt = $this->pdo->prepare('SELECT file_url FROM event_documents WHERE event_id = :id');
        $stmt->execute(['id' => $eventId]);
        self::assertSame('/uploads/event-docs/a.pdf', $stmt->fetchColumn());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function unsafeNestedProvider(): array
    {
        $js = self::JS;
        return [
            'sponsor url' => [['organizerInfo' => ['sponsors' => [['name' => 'A', 'url' => 'https://ok.pl'], ['name' => 'B', 'url' => $js]]]], 'organizerInfo.sponsors[1].url'],
            'logistics maps url' => [['logisticsInfo' => ['parking' => ['status' => 'yes', 'locations' => [['mapsUrl' => $js]]]]], 'logisticsInfo.parking.locations[0].mapsUrl'],
            'gallery album' => [['galleryAlbums' => [['id' => 'g1', 'title' => 'x', 'url' => $js]]], 'galleryAlbums[0].url'],
            'document' => [['documents' => [['id' => 'd1', 'name' => 'x', 'type' => 'other', 'fileUrl' => $js]]], 'documents[0].fileUrl'],
            'distance gpx camel' => [['distances' => [['routeGpxUrl' => $js]]], 'distances[0].routeGpxUrl'],
            'distance gpx snake' => [['distances' => [['route_gpx_url' => $js]]], 'distances[0].route_gpx_url'],
            'distance route file' => [['distances' => [['routeFiles' => [['name' => 'x', 'url' => $js]]]]], 'distances[0].routeFiles[0].url'],
            'merchandise image' => [['merchandise' => [['name' => 'K', 'price' => 1, 'images' => [['url' => $js]]]]], 'merchandise[0].images[0].url'],
            'live results' => [['liveResultsUrl' => $js], 'liveResultsUrl'],
            'maps url' => [['locationMapsUrl' => 'data:text/html,x'], 'locationMapsUrl'],
            'organizer website' => [['organizerContact' => ['website' => $js]], 'organizerContact.website'],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('unsafeNestedProvider')]
    public function testPatchEventRejectsUnsafeNestedUrls(array $patch, string $field): void
    {
        $eventId = $this->createEvent('nested');
        $snapshot = $this->rawEvent($eventId);

        $response = $this->patchEvent($eventId, $patch);

        $body = $this->json($response);
        self::assertSame(422, $response->getStatusCode(), (string) json_encode($body));
        self::assertSame('invalid_url', $body['error']['code']);
        self::assertSame($field, $body['error']['details']['field'] ?? $body['error']['field'] ?? null);
        self::assertSame($snapshot, $this->rawEvent($eventId));
        self::assertSame(0, $this->countRows('event_gallery_albums', 'event_id', $eventId));
    }

    public function testPatchEventRejectsJsonStringContainerBypass(): void
    {
        $eventId = $this->createEvent('jsonstr');
        $before = $this->rawColumn($eventId, 'registration_embed');

        $response = $this->patchEvent($eventId, [
            'registrationEmbed' => '{"mode":"external_embed","externalUrl":"javascript:x"}',
        ]);

        $this->assertInvalidUrl($response, 'registrationEmbed.externalUrl', 'Adres zewnętrznego formularza zapisów');
        self::assertSame($before, $this->rawColumn($eventId, 'registration_embed'));
    }

    public function testPatchEventRejectsArrayLeaf(): void
    {
        $eventId = $this->createEvent('arrleaf');
        $response = $this->patchEvent($eventId, ['liveResultsUrl' => ['javascript:x']]);
        $this->assertInvalidUrl($response, 'liveResultsUrl', 'Link do wyników na żywo');
    }

    public function testPatchEventAllowsClearingUrls(): void
    {
        $eventId = $this->createEvent('clear');
        $response = $this->patchEvent($eventId, ['liveResultsUrl' => '', 'locationMapsUrl' => null]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testCreateEventWithUnsafeUrlCreatesNothing(): void
    {
        $slug = $this->prefix . '-rejected';
        $creditsBefore = $this->organizerCounters();

        $response = $this->request('POST', '/api/v1/events', $this->eventPayload($slug, [
            'registrationEmbed' => ['enabled' => true, 'mode' => 'external_embed', 'externalUrl' => self::JS],
        ]), $this->orgAuth());

        $this->assertInvalidUrl($response, 'registrationEmbed.externalUrl', 'Adres zewnętrznego formularza zapisów');
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM events WHERE slug = :s');
        $stmt->execute(['s' => $slug]);
        self::assertSame(0, (int) $stmt->fetchColumn());
        self::assertSame($creditsBefore, $this->organizerCounters());
    }

    public function testNonManagerStillGets403(): void
    {
        $eventId = $this->createEvent('foreign');
        $response = $this->request(
            'PATCH',
            '/api/v1/events/' . $eventId,
            ['liveResultsUrl' => self::JS],
            $this->bearer($this->otherOrganizer['id'], 'organizer', $this->otherOrganizer['email'])
        );
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testCreateExternalEventValidatesImageUrl(): void
    {
        $response = $this->request('POST', '/api/v1/events/external', [
            'name' => 'Ext', 'date' => '2026-05-01', 'imageUrl' => self::JS,
        ], $this->adminAuth());
        $this->assertInvalidUrl($response, 'imageUrl', 'Zdjęcie wydarzenia');
    }

    // ---- participant-controlled fields --------------------------------------------------

    public function testParticipantCannotStoreUnsafeWebsite(): void
    {
        $auth = $this->bearer($this->participant['id'], 'participant', $this->participant['email']);
        $path = '/api/v1/users/' . $this->participant['id'];

        $bad = $this->request('PATCH', $path, ['website' => self::JS], $auth);
        $this->assertInvalidUrl($bad, 'website', 'Strona www');

        $good = $this->request('PATCH', $path, ['website' => 'https://x.pl'], $auth);
        self::assertSame(200, $good->getStatusCode(), (string) $good->getBody());
        $stmt = $this->pdo->prepare('SELECT website FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->participant['id']]);
        self::assertSame('https://x.pl', $stmt->fetchColumn());
    }

    public function testParticipantProofUrlsAreValidated(): void
    {
        $auth = $this->bearer($this->participant['id'], 'participant', $this->participant['email']);
        foreach (['/api/v1/virtual-results', '/api/v1/challenge-entries'] as $path) {
            $bad = $this->request('POST', $path, ['eventId' => 'e', 'challengeId' => 'c', 'proofUrl' => self::JS], $auth);
            $this->assertInvalidUrl($bad, 'proofUrl', 'Link do dowodu');

            $good = $this->request('POST', $path, ['eventId' => 'nope', 'challengeId' => 'nope', 'proofUrl' => 'https://strava.com/a/1'], $auth);
            $body = json_decode((string) $good->getBody(), true);
            self::assertNotSame('invalid_url', $body['error']['code'] ?? null, $path);
        }
    }

    // ---- admin-controlled fields --------------------------------------------------------

    public function testAdminWriteRoutesValidateUrls(): void
    {
        $auth = $this->adminAuth();

        $r = $this->request('POST', '/api/v1/partners', ['name' => 'P', 'logoUrl' => 'https://a.pl/l.png', 'websiteUrl' => self::JS], $auth);
        $this->assertInvalidUrl($r, 'websiteUrl', 'Strona www partnera');

        $r = $this->request('POST', '/api/v1/event-backgrounds', ['name' => 'B', 'imageUrl' => self::JS], $auth);
        $this->assertInvalidUrl($r, 'imageUrl', 'Obraz tła');

        $r = $this->request('POST', '/api/v1/shop/products', [
            'name' => 'Prod', 'slug' => $this->prefix . '-prod', 'description' => 'd', 'category' => 'c',
            'brand' => 'b', 'price' => 10, 'imageUrl' => self::JS,
        ], $auth);
        $this->assertInvalidUrl($r, 'imageUrl', 'Zdjęcie produktu');

        $r = $this->request('POST', '/api/v1/challenges', ['name' => 'C', 'imageUrl' => self::JS], $auth);
        $this->assertInvalidUrl($r, 'imageUrl', 'Zdjęcie wyzwania');

        $r = $this->request('POST', '/api/v1/info-pages', [
            'slug' => $this->prefix . '-page', 'title' => 'T',
            'content' => [['type' => 'cards', 'items' => [['title' => 'x', 'link' => self::JS]]]],
        ], $auth);
        $this->assertInvalidUrl($r, 'content[0].items[0].link', 'Link w sekcji strony');

        $eventId = $this->createEvent('resdoc');
        $this->pdo->prepare("UPDATE events SET publication_status = 'published' WHERE id = :id")->execute(['id' => $eventId]);
        $r = $this->request('POST', '/api/v1/events/' . $eventId . '/result-documents', ['name' => 'W', 'fileUrl' => self::JS], $auth);
        $this->assertInvalidUrl($r, 'fileUrl', 'Plik z wynikami');
        self::assertSame(0, $this->countRows('event_result_documents', 'event_id', $eventId));

        // Nothing above may have been stored.
        self::assertSame(0, $this->countRows('partners', 'name', 'P'));
    }

    public function testAdminPatchPartnerRejectsUnsafeUrlAndAcceptsHttps(): void
    {
        $auth = $this->adminAuth();
        $created = $this->request('POST', '/api/v1/partners', ['name' => $this->prefix . '-partner', 'logoUrl' => 'https://a.pl/l.png', 'websiteUrl' => 'www.partner.pl'], $auth);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $data = $this->json($created)['data'];
        $this->cleanup[] = ['partners', 'id', (string) $data['id']];
        self::assertSame('https://www.partner.pl', $data['websiteUrl']);

        $bad = $this->request('PATCH', '/api/v1/partners/' . $data['id'], ['websiteUrl' => self::JS], $auth);
        $this->assertInvalidUrl($bad, 'websiteUrl', 'Strona www partnera');
    }

    // ---- helpers ------------------------------------------------------------------------

    private function assertInvalidUrl(\Psr\Http\Message\ResponseInterface $response, string $field, string $label): void
    {
        $body = $this->json($response);
        self::assertSame(422, $response->getStatusCode(), (string) json_encode($body));
        self::assertSame('invalid_url', $body['error']['code'] ?? null);
        self::assertStringContainsString('„' . $label . '”', (string) $body['error']['message']);
        self::assertSame($field, $body['error']['details']['field'] ?? $body['error']['field'] ?? null, (string) json_encode($body));
    }

    /** @return array<string, string> */
    private function orgAuth(): array
    {
        return $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    /** @return array<string, string> */
    private function adminAuth(): array
    {
        return $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
    }

    /** @param array<string, mixed> $patch */
    private function patchEvent(string $eventId, array $patch): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/events/' . $eventId, $patch, $this->orgAuth());
    }

    private function createEvent(string $suffix): string
    {
        $response = $this->request('POST', '/api/v1/events', $this->eventPayload($this->prefix . '-' . $suffix), $this->orgAuth());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return (string) $this->json($response)['data']['id'];
    }

    private function rawColumn(string $eventId, string $column): mixed
    {
        $stmt = $this->pdo->prepare("SELECT $column FROM events WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        return $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function rawEvent(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();
        unset($row['updated_at']);
        return $row;
    }

    private function countRows(string $table, string $column, string $value): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = :v");
        $stmt->execute(['v' => $value]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function organizerCounters(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->organizer['id']]);
        $row = $stmt->fetch();
        unset($row['updated_at'], $row['last_login_at']);
        return $row;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(string $slug, array $overrides = []): array
    {
        return array_replace([
            'name' => 'PHPUnit URL Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'URL Test Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [[
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
            ]],
        ], $overrides);
    }
}
