<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\Users\UserRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * B-02 (część): POST /registrations/quote takes the payload of POST /registrations and returns the
 * Rozliczenie lines and the total WITHOUT writing a Zapis. It runs the very same pricing code as
 * create(), so for the same data the quote total equals the total of the Zapis that follows.
 */
final class RegistrationQuoteContractTest extends HttpTestCase
{
    private UserRepository $users;
    private FormFieldRepository $fields;
    private string $prefix;
    private array $p;
    private array $event;
    private string $distanceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->prefix = 'http-quote-' . bin2hex(random_bytes(4));
        $this->p = $this->users->create([
            'name' => 'Quote Participant',
            'email' => $this->prefix . '-p@example.test',
            'password' => 'demo1234',
            'role' => 'participant',
        ]);
        $admin = $this->users->create([
            'name' => 'Quote Admin',
            'email' => $this->prefix . '-admin@example.test',
            'password' => 'demo1234',
            'role' => 'admin',
        ]);

        $slug = $this->prefix . '-event';
        $this->event = (new EventRepository($this->pdo))->create([
            'name' => 'PHPUnit Quote Event', 'slug' => $slug, 'discipline' => 'running',
            'date' => date('Y-m-d', strtotime('+90 days')), 'location' => 'Stadion Testowy', 'city' => 'Warszawa',
            'description' => '<p>Opis</p>', 'imageUrl' => 'https://example.test/img.jpg',
            'organizerId' => $admin['id'], 'organizerName' => 'Quote Admin', 'status' => 'active',
            'registrationDeadline' => date('Y-m-d', strtotime('+60 days')),
            'featured' => false, 'isPrivate' => false, 'paymentConfig' => ['type' => 'admin_gateway'],
            'discountCodes' => [[
                'id' => $this->prefix . '-half', 'code' => 'QUOTE50', 'discountType' => 'percent',
                'discountValue' => 50, 'usedCount' => 0, 'distanceIds' => [], 'active' => true,
            ]],
            'distances' => [[
                'id' => $slug . '-paid', 'name' => 'Paid 10 km', 'length' => 10, 'unit' => 'km', 'categories' => ['Open'],
                'ageCategories' => [], 'specialCategories' => [], 'limit' => 100, 'registeredCount' => 0,
                'price' => 80, 'routeFiles' => [],
            ]],
        ]);
        $this->distanceId = $slug . '-paid';
        // The form catalogue is seeded lazily on first read (also by the public form-fields route);
        // seed it up front so the row counts below isolate what the quote itself writes.
        $this->fields->ensureSystemCatalog($this->event['id']);
        // Same for the Zawodnik profile of the participant.
        $this->quote([]);
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
        $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $this->event['id']]);
        $this->pdo->prepare('DELETE FROM events WHERE slug LIKE :slug')->execute(['slug' => $this->prefix . '-%']);
        $this->pdo->prepare('DELETE c FROM competitors c JOIN users u ON u.id = c.user_id WHERE u.email LIKE :email')
            ->execute(['email' => $this->prefix . '-%@example.test']);
        $this->pdo->prepare('DELETE FROM users WHERE email LIKE :email')->execute(['email' => $this->prefix . '-%@example.test']);
    }

    public function testQuoteTotalAndLinesEqualTheZapisCreatedFromTheSameData(): void
    {
        $meal = $this->choiceField('Posilek', [['key' => 'veg', 'label' => 'Wegetarianski', 'price' => 5.5], ['key' => 'std', 'label' => 'Standardowy', 'price' => 0]]);
        $draw = $this->choiceField('Losowanie', [['key' => 'yes', 'label' => 'Biore udzial', 'price' => -5]]);
        $extras = $this->choiceField('Dodatki', [['key' => 'a', 'label' => 'A', 'price' => 3], ['key' => 'b', 'label' => 'B', 'price' => 4]], 'multi_choice');

        $scenarios = [
            'start fee only' => [],
            'positive variant' => ['formFieldAnswers' => [['fieldId' => $meal['id'], 'value' => 'veg']]],
            'negative variant' => ['formFieldAnswers' => [['fieldId' => $draw['id'], 'value' => 'yes']]],
            'multi choice + discount code' => [
                'discountCode' => 'QUOTE50',
                'formFieldAnswers' => [
                    ['fieldId' => $extras['id'], 'value' => ['a', 'b']],
                    ['fieldId' => $meal['id'], 'value' => 'veg'],
                    ['fieldId' => $draw['id'], 'value' => 'yes'],
                ],
            ],
        ];

        foreach ($scenarios as $name => $extra) {
            $quote = $this->quote($extra);
            self::assertSame(200, $quote->getStatusCode(), $name);
            $quoted = $this->json($quote)['data'];

            $created = $this->create($extra);
            self::assertSame(201, $created->getStatusCode(), $name);
            $zapis = $this->json($created)['data'];

            self::assertEquals($zapis['totalAmount'], $quoted['totalAmount'], $name . ': total');
            self::assertSame(
                array_map(static fn (array $i): array => [$i['name'], $i['basis'], (float) $i['amount']], $zapis['settlementItems']),
                array_map(static fn (array $i): array => [$i['name'], $i['basis'], (float) $i['amount']], $quoted['settlementItems']),
                $name . ': lines'
            );
        }
    }

    public function testQuoteWritesNothing(): void
    {
        $meal = $this->choiceField('Posilek', [['key' => 'veg', 'label' => 'Wegetarianski', 'price' => 5]]);
        $before = $this->snapshot();

        $response = $this->quote([
            'discountCode' => 'QUOTE50',
            'formFieldAnswers' => [['fieldId' => $meal['id'], 'value' => 'veg']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertEquals(45.0, $this->json($response)['data']['totalAmount'], '50% off the 80 start fee + 5 variant');
        self::assertSame($before, $this->snapshot(), 'no Zapis, answer, settlement line, counter bump or discount use');
    }

    public function testInvalidFormIsRejectedLikeCreate(): void
    {
        $this->fields->createCustom($this->event['id'], ['fieldType' => 'short_text', 'label' => 'Rozmiar buta', 'state' => 'required']);

        $response = $this->quote([]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('registration_form_invalid', $this->json($response)['error']['code']);
    }

    public function testInvalidDiscountCodeIsRejectedLikeCreate(): void
    {
        $response = $this->quote(['discountCode' => 'NOPE']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('discount_code_invalid', $this->json($response)['error']['code']);
    }

    public function testQuoteRequiresAuthentication(): void
    {
        $response = $this->request('POST', '/api/v1/registrations/quote', [
            'eventId' => $this->event['id'],
            'distanceId' => $this->distanceId,
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testQuoteForUnknownEventIs404(): void
    {
        $response = $this->request('POST', '/api/v1/registrations/quote', [
            'eventId' => 'nope',
            'distanceId' => $this->distanceId,
        ], $this->bearer($this->p['id'], (string) $this->p['role'], $this->p['email']));

        self::assertSame(404, $response->getStatusCode());
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $count = function (string $sql, array $params): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        $event = ['e' => $this->event['id']];

        return [
            'registrations' => $count('SELECT COUNT(*) FROM registrations WHERE event_id = :e', $event),
            'settlement' => $count('SELECT COUNT(*) FROM registration_settlement_items i JOIN registrations r ON r.id = i.registration_id WHERE r.event_id = :e', $event),
            'answers' => $count('SELECT COUNT(*) FROM form_field_answers a JOIN form_fields f ON f.id = a.form_field_id WHERE f.event_id = :e', $event),
            'form_fields' => $count('SELECT COUNT(*) FROM form_fields WHERE event_id = :e', $event),
            'registered_count' => $count('SELECT registered_count FROM event_distances WHERE id = :d', ['d' => $this->distanceId]),
            'discount_used' => $count('SELECT used_count FROM event_discount_codes WHERE event_id = :e', $event),
            'competitors' => $count('SELECT COUNT(*) FROM competitors WHERE user_id = :u', ['u' => $this->p['id']]),
        ];
    }

    private function choiceField(string $label, array $options, string $type = 'single_choice'): array
    {
        return $this->fields->createCustom($this->event['id'], ['fieldType' => $type, 'label' => $label, 'state' => 'shown', 'options' => $options]);
    }

    /** @param array<string, mixed> $extra */
    private function quote(array $extra): \Psr\Http\Message\ResponseInterface
    {
        return $this->send('/api/v1/registrations/quote', $extra);
    }

    /** @param array<string, mixed> $extra */
    private function create(array $extra): \Psr\Http\Message\ResponseInterface
    {
        return $this->send('/api/v1/registrations', $extra);
    }

    /** @param array<string, mixed> $extra */
    private function send(string $path, array $extra): \Psr\Http\Message\ResponseInterface
    {
        $payload = array_merge([
            'eventId' => $this->event['id'],
            'distanceId' => $this->distanceId,
            'category' => 'Open',
        ], $extra);

        return $this->request('POST', $path, $payload, $this->bearer($this->p['id'], (string) $this->p['role'], $this->p['email']));
    }
}
