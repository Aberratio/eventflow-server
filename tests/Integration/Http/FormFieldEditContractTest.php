<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldAnswerRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * PATCH /events/{id}/form-fields/{fieldId} may change a custom field's
 * type and its variants (with prices, also negative) instead of delete + add.
 * Safety rule: a field that already has answers keeps its type, and a variant
 * used by an answer cannot be removed (409); adding / relabelling / repricing
 * variants is always fine.
 */
final class FormFieldEditContractTest extends HttpTestCase
{
    private UserRepository $users;
    private EventRepository $events;
    private FormFieldRepository $fields;
    private FormFieldAnswerRepository $answers;
    private RegistrationRepository $registrations;
    private string $prefix;
    private array $organizer;
    private array $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->events = new EventRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->answers = new FormFieldAnswerRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->prefix = 'form-field-edit-' . bin2hex(random_bytes(4));

        $this->organizer = $this->users->create([
            'name' => 'Edit Organizer',
            'email' => $this->prefix . '-organizer@example.test',
            'password' => 'demo1234',
            'role' => 'organizer',
            'eventLimit' => 5,
        ]);
        $slug = $this->prefix . '-event';
        $this->event = $this->events->createForOrganizer([
            'name' => 'Edit Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => $this->organizer['id'],
            'organizerName' => $this->organizer['name'],
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
        ], $this->organizer, $this->organizer['id']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $this->pdo->prepare('DELETE FROM form_field_answers WHERE form_field_id IN (SELECT id FROM form_fields WHERE event_id = :id)')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_payment_methods WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testTypeAndVariantsCanBeChangedWhenNoAnswersExist(): void
    {
        $field = $this->createChoiceField();

        $response = $this->patch($field['id'], [
            'fieldType' => FormFieldType::MULTI_CHOICE,
            'options' => [
                ['key' => 's', 'label' => 'S (mała)', 'price' => 0],
                ['key' => 'vege', 'label' => 'Wege', 'price' => -5.5],
                ['label' => 'Nowy', 'price' => 12],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(FormFieldType::MULTI_CHOICE, $data['fieldType']);
        self::assertSame(['s', 'vege', 'Nowy'], array_column($data['options'], 'key'));
        self::assertSame(['S (mała)', 'Wege', 'Nowy'], array_column($data['options'], 'label'));
        self::assertEquals([0.0, -5.5, 12.0], array_column($data['options'], 'price'));
        self::assertSame($field['fieldKey'], $data['fieldKey'], 'the key stays stable');
    }

    public function testChangingToNonChoiceTypeDropsOptions(): void
    {
        $field = $this->createChoiceField();

        $response = $this->patch($field['id'], ['fieldType' => FormFieldType::SHORT_TEXT]);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(FormFieldType::SHORT_TEXT, $data['fieldType']);
        self::assertEmpty($data['options']);
    }

    public function testTypeChangeWithExistingAnswersIsRejectedWith409(): void
    {
        $field = $this->createChoiceField();
        $this->answers->saveAnswer($this->fields, $field['id'], $this->createRegistration(), 's');

        $response = $this->patch($field['id'], ['fieldType' => FormFieldType::SHORT_TEXT]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('form_field_has_answers', $this->json($response)['error']['code']);
        self::assertSame(FormFieldType::SINGLE_CHOICE, $this->fields->find($field['id'])['fieldType']);
    }

    public function testSameTypeWithExistingAnswersIsNotAConflict(): void
    {
        $field = $this->createChoiceField();
        $this->answers->saveAnswer($this->fields, $field['id'], $this->createRegistration(), 's');

        $response = $this->patch($field['id'], ['fieldType' => FormFieldType::SINGLE_CHOICE, 'label' => 'Koszulka']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUsedVariantCannotBeRemovedButAddRelabelAndRepriceAreAllowed(): void
    {
        $field = $this->createChoiceField();
        $this->answers->saveAnswer($this->fields, $field['id'], $this->createRegistration(), 's');

        $removed = $this->patch($field['id'], ['options' => [['key' => 'vege', 'label' => 'Wege', 'price' => -5]]]);
        self::assertSame(409, $removed->getStatusCode());
        self::assertSame('form_field_option_in_use', $this->json($removed)['error']['code']);
        self::assertCount(2, $this->fields->find($field['id'])['options'], 'nothing was saved');

        $ok = $this->patch($field['id'], ['options' => [
            ['key' => 's', 'label' => 'S nowa etykieta', 'price' => 3],
            ['key' => 'new', 'label' => 'Nowy', 'price' => -2],
        ]]);
        // 'vege' was unused, so dropping it is fine; 's' is kept and repriced.
        self::assertSame(200, $ok->getStatusCode());
        $options = $this->json($ok)['data']['options'];
        self::assertSame(['s', 'new'], array_column($options, 'key'));
        self::assertEquals([3.0, -2.0], array_column($options, 'price'));
    }

    public function testUsedVariantOfMultiChoiceIsProtectedToo(): void
    {
        $field = $this->fields->createCustom((string) $this->event['id'], [
            'label' => 'Posiłki',
            'fieldType' => FormFieldType::MULTI_CHOICE,
            'options' => [['key' => 'a', 'label' => 'A', 'price' => 0], ['key' => 'b', 'label' => 'B', 'price' => 0]],
            'state' => 'shown',
        ]);
        $this->answers->saveAnswer($this->fields, $field['id'], $this->createRegistration(), ['b']);

        $response = $this->patch($field['id'], ['options' => [['key' => 'a', 'label' => 'A', 'price' => 0]]]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('form_field_option_in_use', $this->json($response)['error']['code']);
    }

    public function testInvalidTypeAndDuplicateVariantKeysAreValidationErrors(): void
    {
        $field = $this->createChoiceField();

        $badType = $this->patch($field['id'], ['fieldType' => 'nope']);
        self::assertSame(422, $badType->getStatusCode());

        $dup = $this->patch($field['id'], ['options' => [['key' => 'x', 'label' => 'X'], ['key' => 'x', 'label' => 'Y']]]);
        self::assertSame(422, $dup->getStatusCode());
    }

    public function testSystemFieldTypeCannotBeChanged(): void
    {
        $system = $this->fields->ensureSystemCatalog((string) $this->event['id'])[0];

        $response = $this->patch($system['id'], ['fieldType' => FormFieldType::SHORT_TEXT]);

        self::assertSame(422, $response->getStatusCode());
    }

    private function patch(string $fieldId, array $payload): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'PATCH',
            '/api/v1/events/' . $this->event['id'] . '/form-fields/' . $fieldId,
            $payload,
            $this->bearer($this->organizer['id'], 'organizer', $this->organizer['email'])
        );
    }

    private function createChoiceField(): array
    {
        return $this->fields->createCustom((string) $this->event['id'], [
            'label' => 'Rozmiar koszulki',
            'fieldType' => FormFieldType::SINGLE_CHOICE,
            'options' => [['key' => 's', 'label' => 'S', 'price' => 0], ['key' => 'vege', 'label' => 'Wege', 'price' => -5]],
            'state' => 'shown',
        ]);
    }

    private function createRegistration(): string
    {
        $registration = $this->registrations->create([
            'eventId' => (string) $this->event['id'],
            'eventName' => (string) $this->event['name'],
            'userId' => 'phpunit-user-' . bin2hex(random_bytes(4)),
            'userName' => 'Jan Testowy',
            'userEmail' => 'jan.testowy@example.test',
            'distanceId' => (string) $this->event['distances'][0]['id'],
            'distanceName' => '10 km',
            'category' => 'Open',
        ]);
        return (string) $registration['id'];
    }
}
