<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * B-07: POST /registrations takes the form answers (`formFieldAnswers`) and the
 * identity-section values (`identity`) in the same transaction as the Zapis, and the
 * priced variants of choice fields land in the frozen Rozliczenie (spec 07 step 4).
 */
final class RegistrationFormAnswersContractTest extends HttpTestCase
{
    private UserRepository $users;
    private FormFieldRepository $fields;
    private string $prefix;
    private array $p;
    private array $event;
    private string $paidDistanceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->prefix = 'http-regform-' . bin2hex(random_bytes(4));
        $this->p = $this->users->create([
            'name' => 'RegForm Participant',
            'email' => $this->prefix . '-p@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $admin = $this->users->create([
            'name' => 'RegForm Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);

        $slug = $this->prefix . '-event';
        $this->event = (new EventRepository($this->pdo))->create([
            'name' => 'PHPUnit RegForm Event', 'slug' => $slug, 'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+90 days')), 'location' => 'Stadion Testowy', 'city' => 'Warszawa',
            'description' => '<p>Opis</p>', 'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $admin['id'], 'organizerName' => 'RegForm Admin', 'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+60 days')),
            'featured' => false, 'isPrivate' => false, 'paymentConfig' => ['type' => 'admin_gateway'],
            'discountCodes' => [[
                'id' => $this->prefix . '-full', 'code' => 'B07FULL', 'discountType' => 'percent',
                'discountValue' => 100, 'usedCount' => 0, 'distanceIds' => [], 'active' => true,
            ]],
            'distances' => [[
                'id' => $slug . '-paid', 'name' => 'Paid 10 km', 'length' => 10, 'unit' => 'km', 'categories' => ['Open'],
                'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0,
                'price' => 80, 'routeFiles' => [],
            ]],
        ]);
        $this->paidDistanceId = $slug . '-paid';
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
        $this->pdo->prepare('DELETE FROM event_discount_codes WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testPricedVariantLandsInSettlementAfterStartFeeAndBeforeTotal(): void
    {
        $meal = $this->choiceField('Posilek', [['key' => 'veg', 'label' => 'Wegetarianski', 'price' => 5], ['key' => 'std', 'label' => 'Standardowy', 'price' => 0]]);

        $response = $this->post(['formFieldAnswers' => [['fieldId' => $meal['id'], 'value' => 'veg']]]);
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['distance_price', 'modifier'], array_column($data['settlementItems'], 'basis'));
        self::assertEquals(5.0, $data['settlementItems'][1]['amount']);
        self::assertStringContainsString('Wegetarianski', $data['settlementItems'][1]['name']);
        self::assertEquals(85.0, $data['totalAmount']);
        self::assertEquals(80.0, $data['pricePaid'], 'modifiers are not part of the oplata startowa');
        self::assertSame('veg', $this->answerOf($data['id'], $meal['id']));
    }

    public function testNegativeModifierReducesTotalAndClientCannotSetTheAmount(): void
    {
        $draw = $this->choiceField('Losowanie', [['key' => 'yes', 'label' => 'Biore udzial w losowaniu', 'price' => -5]]);

        $response = $this->post(['formFieldAnswers' => [['fieldId' => $draw['id'], 'value' => 'yes', 'price' => -80, 'amount' => -80]]]);
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertEquals(-5.0, $data['settlementItems'][1]['amount']);
        self::assertEquals(75.0, $data['totalAmount']);
    }

    public function testNegativeModifierNeverPushesTotalBelowZero(): void
    {
        $draw = $this->choiceField('Wielki rabat', [['key' => 'yes', 'label' => 'Tak', 'price' => -500]]);

        $response = $this->post(['formFieldAnswers' => [['fieldId' => $draw['id'], 'value' => 'yes']]]);
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertEquals(0.0, $data['totalAmount']);
        self::assertSame('confirmed', $data['status'], 'nothing due => born confirmed');
    }

    public function testDiscountCodeBitesOnlyTheStartFeeNotTheModifiers(): void
    {
        $meal = $this->choiceField('Posilek', [['key' => 'veg', 'label' => 'Wegetarianski', 'price' => 5]]);

        $response = $this->post(['discountCode' => 'B07FULL', 'formFieldAnswers' => [['fieldId' => $meal['id'], 'value' => 'veg']]]);
        $data = $this->json($response)['data'];

        self::assertSame(['distance_price', 'discount_code', 'modifier'], array_column($data['settlementItems'], 'basis'));
        self::assertEquals(5.0, $data['totalAmount']);
    }

    public function testMultiChoiceSumsEverySelectedVariant(): void
    {
        $extras = $this->choiceField('Dodatki', [
            ['key' => 'a', 'label' => 'A', 'price' => 3], ['key' => 'b', 'label' => 'B', 'price' => 4], ['key' => 'c', 'label' => 'C', 'price' => 100],
        ], 'multi_choice');

        $response = $this->post(['formFieldAnswers' => [['fieldId' => $extras['id'], 'value' => ['a', 'b']]]]);
        $data = $this->json($response)['data'];

        self::assertEquals(87.0, $data['totalAmount']);
        self::assertCount(3, $data['settlementItems']);
    }

    public function testMissingRequiredCustomFieldIsRejectedPerFieldAndNothingIsCreated(): void
    {
        $must = $this->fields->createCustom($this->event['id'], ['fieldType' => 'short_text', 'label' => 'Rozmiar buta', 'state' => 'required']);

        $response = $this->post([]);
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('registration_form_invalid', $body['error']['code']);
        $fieldErrors = $body['error']['details']['fields'];
        self::assertSame($must['id'], $fieldErrors[0]['fieldId']);
        self::assertSame('required', $fieldErrors[0]['code']);
        self::assertSame('To pole jest wymagane.', $fieldErrors[0]['message']);
        self::assertSame(0, $this->countRegistrations());
    }

    public function testInvalidVariantIsRejectedAndRollsBackTheWholeZapis(): void
    {
        $meal = $this->choiceField('Posilek', [['key' => 'veg', 'label' => 'Wegetarianski', 'price' => 5]]);

        $response = $this->post(['formFieldAnswers' => [['fieldId' => $meal['id'], 'value' => 'nope']]]);
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_value', $body['error']['details']['fields'][0]['code']);
        self::assertSame(0, $this->countRegistrations());
    }

    public function testAnswerForForeignOrUnknownFieldIsRejected(): void
    {
        $response = $this->post(['formFieldAnswers' => [['fieldId' => 'does-not-exist', 'value' => 'x']]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('unknown_field', $this->json($response)['error']['details']['fields'][0]['code']);
        self::assertSame(0, $this->countRegistrations());
    }

    public function testHiddenFieldCannotBeAnswered(): void
    {
        $hidden = $this->fields->createCustom($this->event['id'], ['fieldType' => 'short_text', 'label' => 'Ukryte', 'state' => 'hidden']);

        $response = $this->post(['formFieldAnswers' => [['fieldId' => $hidden['id'], 'value' => 'x']]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('unknown_field', $this->json($response)['error']['details']['fields'][0]['code']);
    }

    public function testIdentityValuesAreStoredOnTheZawodnik(): void
    {
        $this->setSystemState('phone', 'required');
        $this->setSystemState('city', 'shown');
        $this->setSystemState('club', 'shown');

        $response = $this->post(['identity' => ['phone' => '+48 600 100 200', 'city' => 'Gdansk', 'club' => 'KB Test']]);

        self::assertSame(201, $response->getStatusCode());
        $row = $this->competitorRow();
        self::assertSame('+48 600 100 200', $row['phone']);
        self::assertSame('Gdansk', $row['city']);
        self::assertSame('KB Test', $row['club']);
    }

    public function testRequiredIdentityFieldMissingIsRejected(): void
    {
        $this->setSystemState('phone', 'required');
        $this->setSystemState('postal_code', 'required');

        $response = $this->post(['identity' => ['postalCode' => '80-001']]);
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('registration_form_invalid', $body['error']['code']);
        self::assertSame(['phone'], array_column($body['error']['details']['fields'], 'fieldKey'));
        self::assertSame('required', $body['error']['details']['fields'][0]['code']);
        self::assertSame(0, $this->countRegistrations());
    }

    public function testHiddenIdentityValueIsNotStored(): void
    {
        $response = $this->post(['identity' => ['address' => 'ul. Ukryta 1']]);

        self::assertSame(201, $response->getStatusCode());
        self::assertNull($this->competitorRow()['address']);
    }

    public function testFormWithoutAnswersStillWorksWhenNothingIsRequired(): void
    {
        $this->fields->createCustom($this->event['id'], ['fieldType' => 'short_text', 'label' => 'Opcjonalne', 'state' => 'shown']);

        self::assertSame(201, $this->post([])->getStatusCode());
    }

    /** @param list<array<string, mixed>> $options */
    private function choiceField(string $label, array $options, string $type = 'single_choice'): array
    {
        return $this->fields->createCustom($this->event['id'], ['fieldType' => $type, 'label' => $label, 'state' => 'shown', 'options' => $options]);
    }

    private function setSystemState(string $key, string $state): void
    {
        foreach ($this->fields->ensureSystemCatalog($this->event['id']) as $field) {
            if ($field['fieldKey'] === $key) {
                $this->fields->updateState($field['id'], $state);
            }
        }
    }

    private function answerOf(string $registrationId, string $fieldId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value_text FROM form_field_answers WHERE registration_id = :r AND form_field_id = :f');
        $stmt->execute(['r' => $registrationId, 'f' => $fieldId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @return array<string, mixed> */
    private function competitorRow(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM competitors WHERE user_id = :u');
        $stmt->execute(['u' => $this->p['id']]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function countRegistrations(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = :event');
        $stmt->execute(['event' => $this->event['id']]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $extra */
    private function post(array $extra): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/api/v1/registrations', array_merge([
            'eventId' => $this->event['id'],
            'distanceId' => $this->paidDistanceId,
            'category' => 'Open',
        ], $extra), $this->bearer($this->p['id'], (string) $this->p['role'], $this->p['email']));
    }
}
