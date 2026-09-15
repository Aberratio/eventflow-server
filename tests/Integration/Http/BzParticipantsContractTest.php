<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Support\Env;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E3.3: "Pull uczestników" — GET /api/v1/events/{eventId}/bz/participants,
 * the endpoint Biuro Zawodów's own polling calls (issue 12 §1/§5/§6).
 *
 * Two independent auth layers are covered by the matrix below: E3.1's
 * `X-API-Key` (ApiKeyAuthMiddleware, wired onto a real route for the first
 * time by this ticket) and E3.2's per-Event `bz_organization_token`,
 * presented here as `X-BZ-Organization-Token` and compared with
 * `hash_equals()`. Both must pass independently; failing either one (or the
 * Event having `bzIntegrationEnabled = false`) collapses to the exact same
 * generic 403, and an unknown event id is the same generic 404 the rest of
 * this codebase already uses for a hidden/missing Event.
 *
 * The response-shape tests are the money-adjacent regression coverage the
 * ticket calls out explicitly: every financial key must be entirely absent
 * (not merely unused/null), never something this test would miss by only
 * checking happy-path values.
 */
final class BzParticipantsContractTest extends HttpTestCase
{
    private const API_KEY = 'phpunit-bz-api-key';

    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private FormFieldRepository $fields;
    private FormFieldAnswerRepository $answers;
    private string $prefix;

    protected function setUp(): void
    {
        // Must be set before HttpTestCase::setUp() builds the app: routes.php
        // reads ApplicationApiKeyConfig::fromEnv() once, at app-construction
        // time, the same way TpayConfig/PayNowConfig::fromEnv() are read once
        // there too.
        //
        // Env::set, not putenv: Env::get answers from the values loaded out of
        // `.env` first, and `.env` carries an empty INCOMING_API_KEYS for local
        // dev, so putenv's key never reached the config and every request here
        // was authenticated against no keys at all -- a generic 401.
        Env::set('INCOMING_API_KEYS', 'biuro_zawodow=' . self::API_KEY);

        parent::setUp();

        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->answers = new FormFieldAnswerRepository($this->pdo);
        $this->prefix = 'bz-pull-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Env::restore('INCOMING_API_KEYS');

        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $registrationIds = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $registrationIds->execute(['id' => $id]);
            foreach (($registrationIds->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $registrationId) {
                $this->pdo->prepare('DELETE FROM registration_merchandise_orders WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_settlement_items WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM form_field_answers WHERE registration_id = :id')->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_merchandise WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare("DELETE FROM competitors WHERE first_name LIKE 'PHPUnit BZ%'")->execute();
    }

    public function testMissingApiKeyIsRejectedWithGeneric401(): void
    {
        $event = $this->createBzEnabledEvent();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $this->json($response)['error']['code']);
    }

    public function testWrongApiKeyIsRejectedWithGeneric401(): void
    {
        $event = $this->createBzEnabledEvent();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => 'totally-wrong-key', 'X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testRightApiKeyButMissingOrgTokenIsForbidden(): void
    {
        $event = $this->createBzEnabledEvent();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY]
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testRightApiKeyButWrongOrgTokenIsForbidden(): void
    {
        $event = $this->createBzEnabledEvent();

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'not-the-right-token']
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testRightKeyAndTokenButIntegrationDisabledIsForbidden(): void
    {
        $event = $this->createBzEnabledEvent(['bzIntegrationEnabled' => false]);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testNonexistentEventReturnsGeneric404NotAConfigError(): void
    {
        $response = $this->request(
            'GET',
            '/api/v1/events/does-not-exist/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'anything']
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function testRightKeyAndTokenAndEnabledReturns200WithFullRoster(): void
    {
        $event = $this->createBzEnabledEvent();
        $this->seedOneFullRegistration($event);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertTrue($payload['success']);
        self::assertCount(1, $payload['data']);
    }

    /**
     * Regression test: hydrate()'s financial keys must be entirely absent
     * from every entry, not merely null/zero — a future field added to
     * hydrate() must never leak here by accident (issue 12 §5, "bez kwot").
     */
    public function testResponseNeverContainsAnyFinancialField(): void
    {
        $event = $this->createBzEnabledEvent();
        $this->seedOneFullRegistration($event);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );
        $entry = $this->json($response)['data'][0];

        foreach (['settlementItems', 'pricePaid', 'discountAmount', 'insurancePaid', 'totalAmount', 'paymentDetails', 'merchandiseOrders', 'transferTitle', 'refund'] as $forbiddenKey) {
            self::assertArrayNotHasKey($forbiddenKey, $entry, "\"{$forbiddenKey}\" must never appear in the BZ pull payload.");
        }

        self::assertArrayHasKey('merchandise', $entry);
        foreach ($entry['merchandise'] as $line) {
            self::assertArrayNotHasKey('price', $line, 'Merchandise lines must carry item/size/quantity only, never a price.');
            self::assertSame(['item', 'size', 'quantity'], array_keys($line));
        }
    }

    public function testPaymentStatusIsAPlainBoolean(): void
    {
        $event = $this->createBzEnabledEvent();
        $this->seedOneFullRegistration($event);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );
        $entry = $this->json($response)['data'][0];

        self::assertIsBool($entry['paid']);
        self::assertFalse($entry['paid'], 'A freshly created, not-yet-paid Zapis must report paid = false.');
    }

    public function testIdentityCodeAndDistanceCategoryStartNumberAreExposed(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId, 'identityCode' => $identityCode] = $this->seedOneFullRegistration($event);

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );
        $entry = current(array_filter($this->json($response)['data'], static fn (array $r): bool => $r['registrationId'] === $registrationId));

        self::assertNotFalse($entry);
        self::assertSame($identityCode, $entry['identityCode']);
        self::assertSame('10 km', $entry['distanceName']);
        self::assertSame('Open', $entry['category']);
        self::assertArrayHasKey('bibNumber', $entry);
        self::assertArrayHasKey('qrCode', $entry);
        self::assertNotEmpty($entry['qrCode']);
    }

    /**
     * Custom-field filtering: only a field explicitly flagged
     * `visibleToBz = true` rides along; one left at the default (false)
     * must not appear at all.
     */
    public function testOnlyVisibleToBzCustomFieldsAppearInFormFields(): void
    {
        $event = $this->createBzEnabledEvent();
        $visibleField = $this->fields->createCustom((string) $event['id'], [
            'label' => 'Numer alarmowy',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
            'visibleToBz' => true,
        ]);
        $hiddenField = $this->fields->createCustom((string) $event['id'], [
            'label' => 'Uwagi wewnętrzne organizatora',
            'fieldType' => FormFieldType::SHORT_TEXT,
            'state' => 'shown',
        ]);

        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);
        $this->answers->saveAnswer($this->fields, $visibleField['id'], $registrationId, '+48 111 222 333');
        $this->answers->saveAnswer($this->fields, $hiddenField['id'], $registrationId, 'nie pokazuj tego BZ');

        $response = $this->request(
            'GET',
            '/api/v1/events/' . $event['id'] . '/bz/participants',
            [],
            ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'ORG-TOKEN-123']
        );
        $entry = current(array_filter($this->json($response)['data'], static fn (array $r): bool => $r['registrationId'] === $registrationId));

        $keys = array_column($entry['formFields'], 'fieldKey');
        self::assertContains($visibleField['fieldKey'], $keys);
        self::assertNotContains($hiddenField['fieldKey'], $keys);
        self::assertContains('first_name', $keys, 'Identity-section system fields must always be included.');

        $valuesByKey = array_column($entry['formFields'], 'value', 'fieldKey');
        self::assertSame('+48 111 222 333', $valuesByKey[$visibleField['fieldKey']]);
    }

    /**
     * @return array{id: string, slug: string, bzOrganizationToken: ?string, bzIntegrationEnabled: bool}
     */
    private function createBzEnabledEvent(array $overrides = []): array
    {
        $slug = $this->prefix . '-event-' . bin2hex(random_bytes(3));
        $event = $this->events->create(array_replace([
            'name' => 'PHPUnit BZ Pull Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-bz-organizer',
            'organizerName' => 'PHPUnit BZ Organizer',
            'status' => 'draft',
            'registrationDeadline' => '2026-09-01',
            'paymentConfig' => ['type' => 'admin_gateway'],
            'bzIntegrationEnabled' => true,
            'bzOrganizationToken' => 'ORG-TOKEN-123',
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
        ], $overrides));

        return $event;
    }

    /** @return array{registrationId: string, competitorId: string, identityCode: string} */
    private function seedOneFullRegistration(array $event): array
    {
        $competitor = $this->competitors->create([
            'firstName' => 'PHPUnit BZ Runner',
            'lastName' => 'Testowy',
        ], skipDuplicateCheck: true);

        $merchandiseId = $this->prefix . '-shirt';
        $this->events->update((string) $event['id'], [
            'merchandise' => [
                ['id' => $merchandiseId, 'name' => 'Koszulka techniczna', 'price' => 65, 'sizes' => ['M'], 'colors' => []],
            ],
        ]);

        $registration = $this->registrations->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'competitorId' => $competitor['id'],
            'userId' => 'phpunit-bz-user-' . bin2hex(random_bytes(4)),
            'userName' => 'PHPUnit BZ Runner Testowy',
            'userEmail' => 'phpunit-bz-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
            'merchandise' => [
                ['merchandiseId' => $merchandiseId, 'size' => 'M', 'quantity' => 1],
            ],
        ]);

        return [
            'registrationId' => (string) $registration['id'],
            'competitorId' => (string) $competitor['id'],
            'identityCode' => (string) $competitor['identityCode'],
        ];
    }
}
