<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use Psr\Http\Message\ResponseInterface;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * A Dystans inherits the event form and may override an inherited Pozycja or add its
 * own. Resolution is used by the public form endpoint, the registration create/quote validation and
 * the organizer editor; other Dystanse and the event-level form are never affected.
 */
final class DistanceFormOverrideContractTest extends HttpTestCase
{
    private UserRepository $users;
    private FormFieldRepository $fields;
    private string $prefix;
    private array $organizer;
    private array $other;
    private array $participant;
    private array $event;
    private string $distA;
    private string $distB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->prefix = 'http-distform-' . bin2hex(random_bytes(4));
        $this->organizer = $this->users->create(['name' => 'DF Organizer', 'email' => $this->prefix . '-org@example.test', 'password' => 'demo1234', 'role' => 'organizer', 'eventLimit' => 5]);
        $this->other = $this->users->create(['name' => 'DF Other', 'email' => $this->prefix . '-other@example.test', 'password' => 'demo1234', 'role' => 'organizer', 'eventLimit' => 5]);
        $this->participant = $this->users->create(['name' => 'DF Participant', 'email' => $this->prefix . '-p@example.test', 'password' => 'demo1234', 'role' => 'participant']);

        $slug = $this->prefix . '-event';
        $this->distA = $slug . '-a';
        $this->distB = $slug . '-b';
        $distance = static fn (string $id, string $name): array => [
            'id' => $id, 'name' => $name, 'length' => 10, 'unit' => 'km', 'categories' => ['Open'],
            'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0,
            'price' => 80, 'routeFiles' => [],
        ];
        $this->event = (new EventRepository($this->pdo))->create([
            'name' => 'PHPUnit DistForm Event', 'slug' => $slug, 'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+90 days')), 'location' => 'Stadion Testowy', 'city' => 'Warszawa',
            'description' => '<p>Opis</p>', 'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $this->organizer['id'], 'organizerName' => 'DF Organizer', 'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+60 days')),
            'featured' => false, 'isPrivate' => false, 'paymentConfig' => ['type' => 'admin_gateway'],
            'distances' => [$distance($this->distA, 'A 10 km'), $distance($this->distB, 'B 5 km')],
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix, $this->event)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
        $stmt->execute(['id' => $this->event['id']]);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $regId) {
            foreach (['form_field_answers', 'registration_merchandise_orders', 'registration_payment_details', 'registration_settlement_items', 'registration_refunds', 'registration_gateway_transactions'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE registration_id = :id")->execute(['id' => $regId]);
            }
        }
        $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testDistanceInheritsTheEventFormAsIs(): void
    {
        $field = $this->customField('Rozmiar koszulki');

        $effective = $this->json($this->get($this->distA))['data'];

        $byKey = array_column($effective, null, 'fieldKey');
        self::assertSame($field['fieldKey'], $byKey[$field['fieldKey']]['fieldKey']);
        self::assertSame($field['id'], $byKey[$field['fieldKey']]['id']);
        self::assertSame(['event'], array_values(array_unique(array_column($effective, 'source'))));
        self::assertCount(count($this->fields->listForEvent((string) $this->event['id'])), $effective);
    }

    public function testOverrideChangesOnlyThatDistance(): void
    {
        $field = $this->customField('Posilek', 'shown');

        $created = $this->request('POST', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth());
        self::assertSame(201, $created->getStatusCode());
        $override = $this->json($created)['data'];
        self::assertSame($this->distA, $override['distanceId']);
        self::assertSame($field['fieldKey'], $override['fieldKey']);
        self::assertNotSame($field['id'], $override['id']);

        $patched = $this->request('PATCH', '/api/v1/events/' . $this->event['id'] . '/form-fields/' . $override['id'], ['state' => 'required', 'label' => 'Posilek A'], $this->organizerAuth());
        self::assertSame(200, $patched->getStatusCode());

        $a = array_column($this->json($this->get($this->distA))['data'], null, 'fieldKey')[$field['fieldKey']];
        $b = array_column($this->json($this->get($this->distB))['data'], null, 'fieldKey')[$field['fieldKey']];
        self::assertSame('override', $a['source']);
        self::assertSame('required', $a['state']);
        self::assertSame('Posilek A', $a['labelOverride']);
        self::assertSame('event', $b['source']);
        self::assertSame('shown', $b['state']);
        self::assertSame('shown', $this->fields->find($field['id'])['state'], 'event-level row is untouched');
        self::assertContains($field['id'], array_column($this->fields->listForEvent((string) $this->event['id']), 'id'));
        self::assertNotContains($override['id'], array_column($this->fields->listForEvent((string) $this->event['id']), 'id'), 'event readers never see overrides');
    }

    public function testOverridingTwiceConflictsAndResetRestoresInheritance(): void
    {
        $field = $this->customField('Posilek', 'shown');
        $this->request('POST', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth());

        $again = $this->request('POST', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth());
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('form_field_override_exists', $this->json($again)['error']['code']);

        $reset = $this->request('DELETE', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth());
        self::assertSame(200, $reset->getStatusCode());
        $a = array_column($this->json($this->get($this->distA))['data'], null, 'fieldKey')[$field['fieldKey']];
        self::assertSame('event', $a['source']);

        $missing = $this->request('DELETE', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth());
        self::assertSame(404, $missing->getStatusCode());
    }

    public function testResetAcceptsTheOverrideIdAsWell(): void
    {
        $field = $this->customField('Posilek', 'shown');
        $override = $this->json($this->request('POST', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth()))['data'];

        $reset = $this->request('DELETE', $this->path($this->distA, "/form-fields/{$override['id']}/override"), [], $this->organizerAuth());

        self::assertSame(200, $reset->getStatusCode());
        self::assertSame([], $this->fields->listForDistanceOnly((string) $this->event['id'], $this->distA));
    }

    public function testInheritedFieldCannotBeRetypedOnTheDistance(): void
    {
        $field = $this->customField('Posilek', 'shown');
        $override = $this->json($this->request('POST', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth()))['data'];

        $retype = $this->request('PATCH', '/api/v1/events/' . $this->event['id'] . '/form-fields/' . $override['id'], ['fieldType' => FormFieldType::SHORT_TEXT], $this->organizerAuth());
        self::assertSame(422, $retype->getStatusCode());

        $options = $this->request('PATCH', '/api/v1/events/' . $this->event['id'] . '/form-fields/' . $override['id'], [
            'options' => [['key' => 'veg', 'label' => 'Wege', 'price' => 7]],
        ], $this->organizerAuth());
        self::assertSame(200, $options->getStatusCode(), 'variants and prices may differ per Dystans');
    }

    public function testSystemFieldOverrideDrivesRegistrationValidationPerDistance(): void
    {
        $phone = $this->systemField('phone');
        $override = $this->json($this->request('POST', $this->path($this->distA, "/form-fields/{$phone['id']}/override"), [], $this->organizerAuth()))['data'];
        $patched = $this->request('PATCH', '/api/v1/events/' . $this->event['id'] . '/form-fields/' . $override['id'], ['state' => 'required'], $this->organizerAuth());
        self::assertSame(200, $patched->getStatusCode());

        $onA = $this->register($this->distA, []);
        self::assertSame(422, $onA->getStatusCode());
        self::assertSame('phone', $this->json($onA)['error']['details']['fields'][0]['fieldKey']);

        $onB = $this->register($this->distB, []);
        self::assertSame(201, $onB->getStatusCode(), 'the other Dystans keeps the event-level (hidden) phone');
    }

    public function testDistanceOnlyFieldIsValidatedAndPricedOnItsDistanceOnly(): void
    {
        $created = $this->request('POST', $this->path($this->distA, '/form-fields'), [
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'label' => 'Rozmiar worka',
            'state' => 'required',
            'options' => [['key' => 'l', 'label' => 'L', 'price' => 9]],
        ], $this->organizerAuth());
        self::assertSame(201, $created->getStatusCode());
        $field = $this->json($created)['data'];
        self::assertSame($this->distA, $field['distanceId']);

        self::assertNotContains($field['id'], array_column($this->fields->listForEvent((string) $this->event['id']), 'id'));
        self::assertContains($field['id'], array_column($this->json($this->get($this->distA))['data'], 'id'));
        self::assertNotContains($field['id'], array_column($this->json($this->get($this->distB))['data'], 'id'));

        // required on A: missing answer rejected
        self::assertSame(422, $this->register($this->distA, [])->getStatusCode());
        // given on A: accepted and priced from the field configuration
        $ok = $this->register($this->distA, ['formFieldAnswers' => [['fieldId' => $field['id'], 'value' => 'l']]]);
        self::assertSame(201, $ok->getStatusCode());
        self::assertEquals(89.0, $this->json($ok)['data']['totalAmount']);
    }

    public function testDistanceOnlyFieldAnswerIsRejectedOnAnotherDistance(): void
    {
        $field = $this->json($this->request('POST', $this->path($this->distA, '/form-fields'), [
            'fieldType' => FormFieldType::SHORT_TEXT, 'label' => 'Tylko A', 'state' => 'shown',
        ], $this->organizerAuth()))['data'];

        $onB = $this->register($this->distB, ['formFieldAnswers' => [['fieldId' => $field['id'], 'value' => 'x']]]);

        self::assertSame(422, $onB->getStatusCode());
    }

    public function testPublicFormEndpointResolvesTheDistance(): void
    {
        $field = $this->customField('Posilek', 'hidden');
        $override = $this->json($this->request('POST', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth()))['data'];
        $this->request('PATCH', '/api/v1/events/' . $this->event['id'] . '/form-fields/' . $override['id'], ['state' => 'shown'], $this->organizerAuth());

        $eventLevel = $this->json($this->request('GET', '/api/v1/events/' . $this->event['id'] . '/form-fields/public'))['data'];
        $forA = $this->json($this->request('GET', '/api/v1/events/' . $this->event['id'] . '/form-fields/public?distanceId=' . $this->distA))['data'];
        $forB = $this->json($this->request('GET', '/api/v1/events/' . $this->event['id'] . '/form-fields/public?distanceId=' . $this->distB))['data'];

        self::assertNotContains($field['fieldKey'], array_column($eventLevel, 'fieldKey'));
        self::assertContains($field['fieldKey'], array_column($forA, 'fieldKey'));
        self::assertNotContains($field['fieldKey'], array_column($forB, 'fieldKey'));
    }

    public function testResetIsRefusedWhileAnswersExistOnTheOverride(): void
    {
        $field = $this->customField('Posilek', 'shown');
        $override = $this->json($this->request('POST', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth()))['data'];
        $registered = $this->register($this->distA, ['formFieldAnswers' => [['fieldId' => $override['id'], 'value' => 'a']]]);
        self::assertSame(201, $registered->getStatusCode());

        $reset = $this->request('DELETE', $this->path($this->distA, "/form-fields/{$field['id']}/override"), [], $this->organizerAuth());

        self::assertSame(409, $reset->getStatusCode());
    }

    public function testOnlyTheEventManagerMayEditDistanceForms(): void
    {
        $field = $this->customField('Posilek', 'shown');
        $path = $this->path($this->distA, "/form-fields/{$field['id']}/override");

        self::assertSame(401, $this->request('POST', $path)->getStatusCode());
        self::assertSame(403, $this->request('POST', $path, [], $this->bearer($this->participant['id'], 'participant', $this->participant['email']))->getStatusCode());
        self::assertSame(403, $this->request('POST', $path, [], $this->bearer($this->other['id'], 'organizer', $this->other['email']))->getStatusCode());
        self::assertSame(403, $this->request('GET', $this->path($this->distA, '/form-fields'), [], $this->bearer($this->other['id'], 'organizer', $this->other['email']))->getStatusCode());
        self::assertSame(201, $this->request('POST', $path, [], $this->bearer($this->users->create(['name' => 'DF Admin', 'email' => $this->prefix . '-admin@example.test', 'password' => 'demo1234', 'role' => 'admin'])['id'], 'admin', $this->prefix . '-admin@example.test'))->getStatusCode());
    }

    public function testUnknownDistanceIsNotFound(): void
    {
        $response = $this->request('GET', $this->path('no-such-distance', '/form-fields'), [], $this->organizerAuth());

        self::assertSame(404, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function customField(string $label, string $state = 'shown'): array
    {
        return $this->fields->createCustom((string) $this->event['id'], [
            'fieldType' => FormFieldType::SINGLE_CHOICE, 'label' => $label, 'state' => $state,
            'options' => [['key' => 'a', 'label' => 'A', 'price' => 0], ['key' => 'b', 'label' => 'B', 'price' => 0]],
        ]);
    }

    /** @return array<string, mixed> */
    private function systemField(string $key): array
    {
        foreach ($this->fields->ensureSystemCatalog((string) $this->event['id']) as $field) {
            if ($field['fieldKey'] === $key) {
                return $field;
            }
        }
        self::fail("no system field {$key}");
    }

    /** @return array<string, string> */
    private function organizerAuth(): array
    {
        return $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email']);
    }

    private function path(string $distanceId, string $suffix): string
    {
        return '/api/v1/events/' . $this->event['id'] . '/distances/' . $distanceId . $suffix;
    }

    private function get(string $distanceId): ResponseInterface
    {
        return $this->request('GET', $this->path($distanceId, '/form-fields'), [], $this->organizerAuth());
    }

    /** @param array<string, mixed> $extra */
    private function register(string $distanceId, array $extra): ResponseInterface
    {
        return $this->request('POST', '/api/v1/registrations', array_merge([
            'eventId' => $this->event['id'],
            'distanceId' => $distanceId,
            'category' => 'Open',
        ], $extra), $this->bearer($this->participant['id'], 'participant', $this->participant['email']));
    }
}
