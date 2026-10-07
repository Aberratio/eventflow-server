<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PP.59: event child rows (distances, payment methods, merchandise, discount codes, gallery albums,
 * documents, pricing variants) are written through a column allowlist, with server-owned ids, a diff
 * sync that never touches counters of kept rows, validation before any write, and one transaction.
 */
final class EventChildMassAssignmentContractTest extends HttpTestCase
{
    private const CHILD_TABLES = [
        'event_distances' => 'event_id',
        'event_payment_methods' => 'event_id',
        'event_merchandise' => 'event_id',
        'event_discount_codes' => 'event_id',
        'event_gallery_albums' => 'event_id',
        'event_documents' => 'event_id',
    ];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private UserRepository $users;
    private string $prefix;
    private array $organizer;
    private array $otherOrganizer;
    private array $admin;
    private array $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->prefix = 'http-pp59-' . bin2hex(random_bytes(4));

        $make = fn (string $suffix, string $role, array $extra = []): array => $this->users->create(array_merge([
            'name' => 'PP59 ' . $suffix,
            'email' => $this->prefix . '-' . $suffix . '@example.test',
            'password' => 'demo1234',
            'role' => $role,
        ], $extra));
        $this->organizer = $make('org', 'organizer', ['eventLimit' => 20]);
        $this->otherOrganizer = $make('org2', 'organizer', ['eventLimit' => 20]);
        $this->admin = $make('admin', 'admin');
        $this->moderator = $make('mod', 'moderator');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slug');
        $stmt->execute(['slug' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM teams WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distance_pricing_variants WHERE distance_id IN (SELECT id FROM event_distances WHERE event_id = :id)')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_insurance_variants WHERE event_id = :id')->execute(['id' => $id]);
            foreach (['event_distances', 'event_merchandise', 'event_discount_codes', 'event_payment_methods',
                'event_gallery_albums', 'event_documents', 'event_result_documents', 'registrations'] as $table) {
                $this->pdo->prepare("DELETE FROM $table WHERE event_id = :id")->execute(['id' => $id]);
            }
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
    }

    // ---- attack matrix ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function injectedKeyProvider(): array
    {
        return [
            'foo' => ['foo'],
            'passwordHash' => ['passwordHash'],
            'sql fragment' => ['name`) SELECT 1 -- '],
            'backtick' => ['x`y'],
            'event_id' => ['event_id'],
            'eventId' => ['eventId'],
            'snake_case' => ['route_gpx_url'],
            'registered_count' => ['registered_count'],
            'used_count' => ['used_count'],
            'sort_order' => ['sort_order'],
        ];
    }

    #[DataProvider('injectedKeyProvider')]
    public function testInjectedColumnKeyIsIgnored(string $key): void
    {
        $e1 = $this->createEvent('inj');
        $e2 = $this->createEvent('inj-victim', true);
        $victimBefore = $this->dump($e2);
        $value = in_array($key, ['event_id', 'eventId'], true) ? $e2 : 'https://example.test/x';
        $item = static fn (array $base): array => $base + [$key => $value];

        $response = $this->patchEvent($e1, [
            'distances' => [$item(['name' => '10 km', 'unit' => 'km', 'price' => 50])],
            'paymentMethods' => [$item(['name' => 'Przelew', 'enabled' => true])],
            'merchandise' => [$item(['name' => 'Koszulka', 'price' => 30])],
            'discountCodes' => [$item(['code' => 'PP59', 'discountType' => 'percent', 'discountValue' => 10])],
            'galleryAlbums' => [$item(['name' => 'Album', 'url' => 'https://example.test/a', 'platform' => 'google'])],
            'documents' => [$item(['name' => 'Regulamin', 'fileUrl' => '/uploads/event-docs/r.pdf', 'fileName' => 'r.pdf'])],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $dump = $this->dump($e1);
        self::assertCount(1, $dump['event_distances']);
        self::assertSame('10 km', $dump['event_distances'][0]['name']);
        self::assertSame($e1, $dump['event_distances'][0]['event_id']);
        self::assertSame(0, (int) $dump['event_distances'][0]['registered_count']);
        self::assertSame($e1, $dump['event_discount_codes'][0]['event_id']);
        self::assertSame(0, (int) $dump['event_discount_codes'][0]['used_count']);
        self::assertNull($dump['event_distances'][0]['route_gpx_url']);
        foreach (self::CHILD_TABLES as $table => $_) {
            self::assertCount(1, $dump[$table], $table);
            self::assertMatchesRegularExpression(self::UUID, (string) $dump[$table][0]['id'], $table);
        }
        self::assertSame($victimBefore, $this->dump($e2));
    }

    public function testForeignEventChildIdIsNotReused(): void
    {
        $e1 = $this->createEvent('own');
        $e2 = $this->createEvent('victim', true, $this->fullChildren('v'));
        $victim = $this->dump($e2);
        $foreign = static fn (string $table): string => (string) $victim[$table][0]['id'];

        $response = $this->patchEvent($e1, [
            'distances' => [['id' => $foreign('event_distances'), 'name' => 'steal', 'unit' => 'km']],
            'paymentMethods' => [['id' => $foreign('event_payment_methods'), 'name' => 'steal']],
            'merchandise' => [['id' => $foreign('event_merchandise'), 'name' => 'steal', 'price' => 1]],
            'discountCodes' => [['id' => $foreign('event_discount_codes'), 'code' => 'STEAL', 'discountType' => 'fixed', 'discountValue' => 1]],
            'galleryAlbums' => [['id' => $foreign('event_gallery_albums'), 'name' => 'steal', 'url' => 'https://example.test/s', 'platform' => 'x']],
            'documents' => [['id' => $foreign('event_documents'), 'name' => 'steal', 'fileUrl' => '/s.pdf', 'fileName' => 's.pdf']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $mine = $this->dump($e1);
        foreach (self::CHILD_TABLES as $table => $_) {
            self::assertCount(1, $mine[$table], $table);
            self::assertNotSame($foreign($table), $mine[$table][0]['id'], $table);
            self::assertMatchesRegularExpression(self::UUID, (string) $mine[$table][0]['id'], $table);
            self::assertSame($e1, $mine[$table][0]['event_id'], $table);
        }
        self::assertSame($victim, $this->dump($e2), 'the other event must not change');
    }

    public function testOverlongAndHostileIdsGetServerIds(): void
    {
        $e = $this->createEvent('ids', false, ['distances' => [['name' => '5 km', 'unit' => 'km']]]);
        $own = (string) $this->dump($e)['event_distances'][0]['id'];

        $ids = [str_repeat('a', 37), str_repeat('b', 200), "'; DROP TABLE x; --", 'dup', 'dup', $own, $own];
        $distances = [];
        foreach ($ids as $i => $id) {
            $distances[] = ['id' => $id, 'name' => 'D' . $i, 'unit' => 'km'];
        }
        $response = $this->patchEvent($e, [
            'distances' => $distances,
            'merchandise' => [['id' => str_repeat('c', 80), 'name' => 'M', 'price' => 1]],
            'paymentMethods' => [['id' => str_repeat('d', 80), 'name' => 'P']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rows = $this->dump($e)['event_distances'];
        self::assertCount(7, $rows);
        $stored = array_column($rows, 'id');
        self::assertCount(7, array_unique($stored));
        foreach ($stored as $id) {
            self::assertMatchesRegularExpression(self::UUID, (string) $id);
        }
        self::assertContains($own, $stored, 'the first occurrence of an own id keeps it');
        self::assertSame($own, $stored[5]);
        self::assertSame(1, $this->countRows('event_merchandise', 'event_id', $e));
    }

    public function testOwnChildIdsAreKeptAndCountersPreserved(): void
    {
        $e = $this->createEvent('counters', false, [
            'distances' => [['name' => '10 km', 'unit' => 'km', 'limit' => 100]],
            'discountCodes' => [['code' => 'ONCE', 'discountType' => 'percent', 'discountValue' => 10, 'maxUses' => 3]],
        ]);
        $before = $this->dump($e);
        $distanceId = (string) $before['event_distances'][0]['id'];
        $codeId = (string) $before['event_discount_codes'][0]['id'];
        $this->pdo->prepare('UPDATE event_distances SET registered_count = 5 WHERE id = :id')->execute(['id' => $distanceId]);
        $this->pdo->prepare('UPDATE event_discount_codes SET used_count = 3, used_by = :ub WHERE id = :id')
            ->execute(['id' => $codeId, 'ub' => json_encode([['userId' => 'u1', 'registrationId' => 'r1']])]);

        $response = $this->patchEvent($e, [
            'distances' => [['id' => $distanceId, 'name' => '10 km renamed', 'unit' => 'km', 'limit' => 90, 'registeredCount' => 0]],
            'discountCodes' => [['id' => $codeId, 'code' => 'ONCE', 'discountType' => 'percent', 'discountValue' => 15, 'maxUses' => 3, 'usedCount' => 0, 'usedBy' => []]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $after = $this->dump($e);
        self::assertSame($distanceId, $after['event_distances'][0]['id']);
        self::assertSame('10 km renamed', $after['event_distances'][0]['name']);
        self::assertSame(90, (int) $after['event_distances'][0]['participant_limit']);
        self::assertSame(5, (int) $after['event_distances'][0]['registered_count']);
        self::assertSame($codeId, $after['event_discount_codes'][0]['id']);
        self::assertSame(15.0, (float) $after['event_discount_codes'][0]['discount_value']);
        self::assertSame(3, (int) $after['event_discount_codes'][0]['used_count']);
        self::assertSame([['userId' => 'u1', 'registrationId' => 'r1']], json_decode((string) $after['event_discount_codes'][0]['used_by'], true));
    }

    public function testNewRowsGetSchemaDefaultCountersEvenWhenClientSendsValues(): void
    {
        $e = $this->createEvent('newcounters', false, [
            'distances' => [['name' => '10 km', 'unit' => 'km', 'registeredCount' => 999]],
            'discountCodes' => [['code' => 'X', 'discountType' => 'fixed', 'discountValue' => 5, 'usedCount' => 50, 'usedBy' => [['userId' => 'x']]]],
        ]);
        $dump = $this->dump($e);
        self::assertSame(0, (int) $dump['event_distances'][0]['registered_count']);
        self::assertSame(0, (int) $dump['event_discount_codes'][0]['used_count']);
        self::assertNull($dump['event_discount_codes'][0]['used_by']);
    }

    public function testSavingDistancesKeepsTeamsAndFormFieldScope(): void
    {
        $e = $this->createEvent('teams', false, ['distances' => [['name' => 'Sztafeta', 'unit' => 'km', 'startType' => 'relay']]]);
        $distanceId = (string) $this->dump($e)['event_distances'][0]['id'];
        $this->pdo->prepare('INSERT INTO teams (id, event_id, distance_id, name, created_at) VALUES (:id, :e, :d, :n, :c)')
            ->execute(['id' => 'pp59-team-' . bin2hex(random_bytes(4)), 'e' => $e, 'd' => $distanceId, 'n' => 'Drużyna A', 'c' => date(DATE_ATOM)]);
        $this->pdo->prepare("INSERT INTO form_fields (id, event_id, distance_id, kind, field_key, storage_location, state) VALUES (:id, :e, :d, 'custom', 'pp59key', 'registration', 'shown')")
            ->execute(['id' => 'pp59-ff-' . bin2hex(random_bytes(4)), 'e' => $e, 'd' => $distanceId]);

        $response = $this->patchEvent($e, [
            'distances' => [['id' => $distanceId, 'name' => 'Sztafeta 4x5', 'unit' => 'km', 'startType' => 'relay']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->countRows('teams', 'distance_id', $distanceId));
        self::assertSame(1, $this->countRows('form_fields', 'distance_id', $distanceId));
        self::assertSame($distanceId, $this->dump($e)['event_distances'][0]['id']);
    }

    public function testRemovedDistanceIsDeletedWithItsVariants(): void
    {
        $e = $this->createEvent('removed', false, ['distances' => [
            ['name' => '5 km', 'unit' => 'km', 'pricingVariants' => [['name' => 'Biuro', 'price' => 70, 'officeOnly' => true]]],
            ['name' => '10 km', 'unit' => 'km', 'pricingVariants' => [['name' => 'Biuro', 'price' => 90, 'officeOnly' => true]]],
        ]]);
        [$d1, $d2] = array_column($this->dump($e)['event_distances'], 'id');
        self::assertSame(1, $this->countRows('event_distance_pricing_variants', 'distance_id', $d2));

        $response = $this->patchEvent($e, ['distances' => [['id' => $d1, 'name' => '5 km', 'unit' => 'km']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([$d1], array_column($this->dump($e)['event_distances'], 'id'));
        self::assertSame(0, $this->countRows('event_distance_pricing_variants', 'distance_id', $d2));
        self::assertSame(1, $this->countRows('event_distance_pricing_variants', 'distance_id', $d1), 'variants of a kept distance stay when the key is absent');
    }

    public function testNewDistanceIdsAreRemappedInDistanceIds(): void
    {
        $e = $this->createEvent('remap');

        $response = $this->patchEvent($e, [
            'distances' => [['id' => 'tmp-1', 'name' => '10 km', 'unit' => 'km']],
            'merchandise' => [['name' => 'Koszulka', 'price' => 1, 'distanceIds' => ['tmp-1']]],
            'discountCodes' => [['code' => 'D', 'discountType' => 'fixed', 'discountValue' => 1, 'distanceIds' => ['tmp-1', 'unknown-id']]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $dump = $this->dump($e);
        $newId = (string) $dump['event_distances'][0]['id'];
        self::assertMatchesRegularExpression(self::UUID, $newId);
        self::assertSame([$newId], json_decode((string) $dump['event_merchandise'][0]['distance_ids'], true));
        self::assertSame([$newId, 'unknown-id'], json_decode((string) $dump['event_discount_codes'][0]['distance_ids'], true));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function invalidChildProvider(): array
    {
        $dist = ['name' => '10 km', 'unit' => 'km'];

        return [
            'distance without name' => [['distances' => [['unit' => 'km']]], 'distances[0].name', 'Nazwa dystansu'],
            'second distance without name' => [['distances' => [$dist, ['unit' => 'km']]], 'distances[1].name', 'Nazwa dystansu'],
            'bad unit' => [['distances' => [['name' => 'a', 'unit' => 'mile']]], 'distances[0].unit', 'Jednostka długości'],
            'name of 181 characters' => [['distances' => [['name' => str_repeat('a', 181), 'unit' => 'km']]], 'distances[0].name', 'Nazwa dystansu'],
            'bad relay deadline' => [['distances' => [$dist + ['relayTeamCompletionDeadline' => 'abc']]], 'distances[0].relayTeamCompletionDeadline', 'Termin kompletowania drużyny'],
            'negative price' => [['distances' => [$dist + ['price' => -5]]], 'distances[0].price', 'Cena dystansu'],
            'non numeric price' => [['distances' => [$dist + ['price' => 'abc']]], 'distances[0].price', 'Cena dystansu'],
            'categories not json' => [['distances' => [$dist + ['categories' => 'not-json']]], 'distances[0].categories', 'Kategorie'],
            'distance not an object' => [['distances' => ['text']], 'distances[0]', 'Dystanse'],
            'distances not a list' => [['distances' => 'text'], 'distances', 'Dystanse'],
            'distances is an object' => [['distances' => ['a' => $dist]], 'distances', 'Dystanse'],
            'variant without price' => [['distances' => [$dist + ['pricingVariants' => [['name' => 'Biuro']]]]], 'distances[0].pricingVariants[0].price', 'Cena wariantu opłaty'],
            'document without fileUrl' => [['documents' => [['name' => 'R']]], 'documents[0].fileUrl', 'Plik dokumentu'],
            'bad document type' => [['documents' => [['name' => 'R', 'fileUrl' => '/a.pdf', 'type' => 'other']]], 'documents[0].type', 'Rodzaj dokumentu'],
            'bad discount type' => [['discountCodes' => [['code' => 'A', 'discountType' => 'x', 'discountValue' => 1]]], 'discountCodes[0].discountType', 'Rodzaj rabatu'],
            'bad quantity mode' => [['merchandise' => [['name' => 'K', 'price' => 1, 'quantityMode' => 'many']]], 'merchandise[0].quantityMode', 'Tryb ilości'],
            'album without url' => [['galleryAlbums' => [['name' => 'A', 'platform' => 'x']]], 'galleryAlbums[0].url', 'Adres albumu'],
            'payment method without name' => [['paymentMethods' => [['enabled' => true]]], 'paymentMethods[0].name', 'Nazwa metody płatności'],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('invalidChildProvider')]
    public function testInvalidChildValueReturns422AndWritesNothing(array $patch, string $field, string $label): void
    {
        $e = $this->createEvent('invalid', false, $this->fullChildren('i'));
        $before = $this->dump($e);
        $nameBefore = $this->scalar('SELECT name FROM events WHERE id = :id', ['id' => $e]);
        $variantsBefore = $this->variantRows($e);

        $response = $this->patchEvent($e, $patch + ['name' => 'Renamed by invalid patch']);

        $body = $this->json($response);
        self::assertSame(422, $response->getStatusCode(), (string) json_encode($body));
        self::assertSame('validation_error', $body['error']['code'] ?? null);
        self::assertStringContainsString('„' . $label . '”', (string) $body['error']['message']);
        self::assertSame($field, $body['error']['details']['field'] ?? null, (string) json_encode($body));
        self::assertSame($nameBefore, $this->scalar('SELECT name FROM events WHERE id = :id', ['id' => $e]));
        self::assertSame($before, $this->dump($e));
        self::assertSame($variantsBefore, $this->variantRows($e));
    }

    public function testInvalidChildOnCreateReturns422AndCreatesNothing(): void
    {
        $slug = $this->prefix . '-create-invalid';
        $response = $this->request('POST', '/api/v1/events', $this->eventPayload($slug, [
            'distances' => [['name' => '10 km', 'unit' => 'furlong']],
        ]), $this->orgAuth());

        $body = $this->json($response);
        self::assertSame(422, $response->getStatusCode(), (string) json_encode($body));
        self::assertSame('distances[0].unit', $body['error']['details']['field'] ?? null);
        self::assertSame(0, $this->countRows('events', 'slug', $slug));
    }

    public function testDocumentWithoutFileNameIsSavedWithUrlBasename(): void
    {
        $e = $this->createEvent('docname');

        $response = $this->patchEvent($e, ['documents' => [['name' => 'Regulamin', 'type' => 'regulamin', 'fileUrl' => '/uploads/event-docs/a.pdf']]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->dump($e)['event_documents'][0];
        self::assertSame('a.pdf', $row['file_name']);
        self::assertSame('application/octet-stream', $row['mime_type']);
    }

    // ---- real client payloads --------------------------------------------------------

    public function testEditorPayloadRoundTripOnExistingEvent(): void
    {
        $e = $this->createEvent('editor', false, [
            'distances' => [['name' => '10 km', 'unit' => 'km', 'limit' => 50, 'price' => 60]],
            'merchandise' => [['name' => 'Koszulka', 'price' => 40]],
            'documents' => [['name' => 'Regulamin', 'type' => 'regulamin', 'fileUrl' => '/uploads/event-docs/r.pdf', 'fileName' => 'r.pdf', 'mimeType' => 'application/pdf']],
        ]);
        $first = $this->dump($e);
        $distanceId = (string) $first['event_distances'][0]['id'];
        $merchId = (string) $first['event_merchandise'][0]['id'];
        $docId = (string) $first['event_documents'][0]['id'];

        for ($save = 1; $save <= 2; $save++) {
            $response = $this->patchEvent($e, $this->editorPayload($distanceId, $merchId, $docId));
            self::assertSame(200, $response->getStatusCode(), 'save #' . $save . ': ' . $response->getBody());
        }

        $dump = $this->dump($e);
        self::assertSame([$distanceId], array_column($dump['event_distances'], 'id'));
        self::assertSame([$merchId], array_column($dump['event_merchandise'], 'id'));
        self::assertSame([$docId], array_column($dump['event_documents'], 'id'));
        self::assertSame(1, $this->countRows('event_distance_pricing_variants', 'distance_id', $distanceId), 'one office variant per distance');
        $variant = $this->variantRows($e)[0];
        self::assertSame('Cena w biurze zawodów', $variant['name']);
        self::assertSame(1, (int) $variant['office_only']);
        self::assertSame(0, (int) $variant['visible_in_public_form']);
        self::assertSame(3, (int) $dump['event_distances'][0]['relay_min_team_size']);
    }

    public function testAdminAdvancedSaveWithHydratedDistances(): void
    {
        $e = $this->createEvent('admin', false, $this->fullChildren('a') + [
            'insuranceVariantIds' => [],
        ]);
        $this->publish($e);
        $before = $this->dump($e);
        $get = $this->request('GET', '/api/v1/events/' . $e, [], $this->adminAuth());
        self::assertSame(200, $get->getStatusCode());
        $event = $this->json($get)['data'];
        self::assertArrayHasKey('paidCount', $event['distances'][0]);

        $response = $this->request('PATCH', '/api/v1/events/' . $e, [
            'distances' => $event['distances'],
            'discountCodes' => $event['discountCodes'],
            'merchandise' => $event['merchandise'],
            'paymentMethods' => $event['paymentMethods'],
            'galleryAlbums' => $event['galleryAlbums'],
            'documents' => $event['documents'],
        ], $this->adminAuth());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $after = $this->dump($e);
        foreach (self::CHILD_TABLES as $table => $_) {
            self::assertSame(array_column($before[$table], 'id'), array_column($after[$table], 'id'), $table . ' ids');
            self::assertSame(array_column($before[$table], 'name') ?: null, array_column($after[$table], 'name') ?: null, $table . ' names');
        }
    }

    public function testInsuranceVariantIdsAreValidated(): void
    {
        $e = $this->createEvent('ins');

        $ok = $this->patchEvent($e, ['insuranceVariantIds' => ['v1', 'v1', 'v2']]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame(2, $this->countRows('event_insurance_variants', 'event_id', $e));

        $bad = $this->patchEvent($e, ['insuranceVariantIds' => [str_repeat('x', 40)]]);
        $body = $this->json($bad);
        self::assertSame(422, $bad->getStatusCode(), (string) json_encode($body));
        self::assertSame('insuranceVariantIds', $body['error']['details']['field'] ?? null);
        self::assertSame(2, $this->countRows('event_insurance_variants', 'event_id', $e), 'nothing written on 422');
    }

    public function testRbacUnchanged(): void
    {
        $e = $this->createEvent('rbac', false, ['paymentMethods' => [['name' => 'Przelew']]]);

        $this->publish($e);
        $foreign = $this->request('PATCH', '/api/v1/events/' . $e, ['distances' => [['name' => 'x', 'unit' => 'km']]], $this->otherAuth());
        self::assertSame(403, $foreign->getStatusCode());

        $mod = $this->request('PATCH', '/api/v1/events/' . $e, [
            'paymentMethods' => [['name' => 'Moderator injected']],
            'galleryAlbums' => [['name' => 'A', 'url' => 'https://example.test/a', 'platform' => 'x']],
        ], $this->bearer($this->moderator['id'], 'moderator', $this->moderator['email']));
        self::assertSame(200, $mod->getStatusCode(), (string) $mod->getBody());
        $dump = $this->dump($e);
        self::assertSame(['Przelew'], array_column($dump['event_payment_methods'], 'name'));
        self::assertCount(1, $dump['event_gallery_albums']);
    }

    // ---- helpers ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function editorPayload(string $distanceId, string $merchId, string $docId): array
    {
        // the key set of useEventFormState.ts buildPayload() for the child collections
        return [
            'name' => 'PHPUnit Editor Event',
            'distances' => [[
                'id' => $distanceId,
                'name' => '10 km',
                'length' => 10,
                'unit' => 'km',
                'startType' => 'relay',
                'relayMinTeamSize' => 3,
                'relayMaxTeamSize' => 5,
                'relayTeamCompletionDeadline' => '2026-09-10',
                'teamAggregationRule' => 'sum',
                'teamPricingMode' => 'per_team',
                'price' => 60,
                'pricingVariants' => [[
                    'id' => 'pv-office-' . $distanceId,
                    'name' => 'Cena w biurze zawodów',
                    'price' => 80,
                    'officeOnly' => true,
                    'visibleInPublicForm' => false,
                    'sortOrder' => 0,
                ]],
                'infoHtml' => '<p>Info</p>',
                'limit' => 50,
                'priceTiers' => [['price' => 55, 'discountValue' => 0, 'endDate' => '2026-08-01']],
                'registeredCount' => 12,
                'categories' => ['Open'],
                'ageCategories' => [],
                'specialCategories' => [],
                'routeMapUrl' => 'https://example.test/map.png',
                'routeFiles' => [['name' => 'trasa', 'url' => 'https://example.test/trasa.gpx']],
                'documents' => [['id' => 'dd1', 'name' => 'Opis trasy', 'fileUrl' => 'https://example.test/trasa.pdf']],
            ]],
            'documents' => [[
                'id' => $docId,
                'name' => 'Regulamin',
                'type' => 'regulamin',
                'fileUrl' => '/uploads/event-docs/r.pdf',
                'fileName' => 'r.pdf',
                'mimeType' => 'application/pdf',
            ]],
            'merchandise' => [[
                'id' => $merchId,
                'name' => 'Koszulka',
                'descriptionHtml' => '<p>Bawełna</p>',
                'brand' => 'Marka',
                'price' => 40,
                'originalPrice' => 50,
                'sizes' => ['S', 'M'],
                'colors' => ['czarny'],
                'imageUrl' => 'https://example.test/k.png',
                'images' => [['url' => 'https://example.test/k1.png']],
                'available' => true,
                'stockCount' => 100,
                'quantityMode' => 'max',
                'quantityMax' => 2,
                'saleFrom' => '2026-07-01',
                'saleTo' => '2026-08-01',
                'distanceIds' => [$distanceId],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function fullChildren(string $tag): array
    {
        return [
            'distances' => [['name' => 'D ' . $tag, 'unit' => 'km', 'limit' => 10, 'price' => 20,
                'pricingVariants' => [['name' => 'Biuro', 'price' => 30, 'officeOnly' => true]]]],
            'paymentMethods' => [['name' => 'Przelew ' . $tag]],
            'merchandise' => [['name' => 'M ' . $tag, 'price' => 5]],
            'discountCodes' => [['code' => 'C' . strtoupper($tag), 'discountType' => 'percent', 'discountValue' => 10]],
            'galleryAlbums' => [['name' => 'A ' . $tag, 'url' => 'https://example.test/' . $tag, 'platform' => 'google']],
            'documents' => [['name' => 'Doc ' . $tag, 'fileUrl' => '/uploads/event-docs/' . $tag . '.pdf', 'fileName' => $tag . '.pdf']],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createEvent(string $suffix, bool $other = false, array $overrides = []): string
    {
        $auth = $other ? $this->otherAuth() : $this->orgAuth();
        $isOther = $other;
        $response = $this->request('POST', '/api/v1/events', $this->eventPayload($this->prefix . '-' . $suffix, $overrides + [
            'organizerId' => $isOther ? $this->otherOrganizer['id'] : $this->organizer['id'],
        ]), $auth);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['data']['id'];
    }

    /** Staff (admin/moderator) may only edit an event that has cleared moderation. */
    private function publish(string $eventId): void
    {
        $this->pdo->prepare("UPDATE events SET publication_status = 'published' WHERE id = :id")->execute(['id' => $eventId]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function dump(string $eventId): array
    {
        $out = [];
        foreach (self::CHILD_TABLES as $table => $column) {
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE $column = :id ORDER BY sort_order, id");
            $stmt->execute(['id' => $eventId]);
            $out[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function variantRows(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT v.id, v.distance_id, v.name, v.price, v.office_only, v.visible_in_public_form, v.sort_order FROM event_distance_pricing_variants v JOIN event_distances d ON d.id = v.distance_id WHERE d.event_id = :id ORDER BY v.distance_id, v.sort_order');
        $stmt->execute(['id' => $eventId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function countRows(string $table, string $column, string $value): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM $table WHERE $column = :v", ['v' => $value]);
    }

    /** @return array<string, string> */
    private function orgAuth(): array
    {
        return $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    /** @return array<string, string> */
    private function otherAuth(): array
    {
        return $this->bearer($this->otherOrganizer['id'], 'organizer', $this->otherOrganizer['email']);
    }

    /** @return array<string, string> */
    private function adminAuth(): array
    {
        return $this->bearer($this->admin['id'], 'admin', $this->admin['email']);
    }

    /** @param array<string, mixed> $patch */
    private function patchEvent(string $eventId, array $patch): ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/events/' . $eventId, $patch, $this->orgAuth());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(string $slug, array $overrides = []): array
    {
        return array_replace([
            'name' => 'PHPUnit PP59 Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis</p>',
            'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => 'PP59 Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'featured' => false,
            'isPrivate' => false,
            'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [],
        ], $overrides);
    }
}
