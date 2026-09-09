<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Admin\AdminRepository;
use ZmierzymyCzas\Domain\Competitors\CompetitorRepository;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldRepository;
use ZmierzymyCzas\Domain\FormFields\FormFieldType;
use ZmierzymyCzas\Domain\Registrations\RegistrationRepository;
use ZmierzymyCzas\Tests\Support\HttpTestCase;

/**
 * E3.5 (issue 12 §2): "Last-write-wins z jawnym śladem" — the return leg of
 * the BZ sync contract, PATCH /api/v1/events/{eventId}/bz/participants/{registrationId}.
 * Mirrors BzParticipantsContractTest's auth matrix (same two layers, same
 * generic error shapes) and adds the LWW-specific coverage: a newer BZ edit
 * applies, a stale one is rejected (and both are logged), and an
 * out-of-scope/payment-shaped key is never trusted even if BZ sends one.
 */
final class BzParticipantSyncContractTest extends HttpTestCase
{
    private const API_KEY = 'phpunit-bz-sync-api-key';

    private EventRepository $events;
    private RegistrationRepository $registrations;
    private CompetitorRepository $competitors;
    private FormFieldRepository $fields;
    private AdminRepository $admin;
    private string $prefix;

    protected function setUp(): void
    {
        putenv('INCOMING_API_KEYS=biuro_zawodow=' . self::API_KEY);

        parent::setUp();

        $this->events = new EventRepository($this->pdo);
        $this->registrations = new RegistrationRepository($this->pdo, null, null, null);
        $this->competitors = new CompetitorRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->admin = new AdminRepository($this->pdo);
        $this->prefix = 'bz-sync-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        putenv('INCOMING_API_KEYS');

        if (!isset($this->pdo, $this->prefix)) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE slug LIKE :slugLike');
        $stmt->execute(['slugLike' => $this->prefix . '-%']);
        foreach (($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $id) {
            $registrationIds = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :id');
            $registrationIds->execute(['id' => $id]);
            foreach (($registrationIds->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $registrationId) {
                $this->pdo->prepare('DELETE FROM registration_payment_details WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM registration_settlement_items WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare('DELETE FROM form_field_answers WHERE registration_id = :id')->execute(['id' => $registrationId]);
                $this->pdo->prepare("DELETE FROM activity_logs WHERE target_type = 'registration' AND target_id = :id")->execute(['id' => $registrationId]);
            }
            $this->pdo->prepare('DELETE FROM form_fields WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM registrations WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM event_distances WHERE event_id = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare("DELETE FROM competitors WHERE first_name LIKE 'PHPUnit BZ Sync%'")->execute();
    }

    public function testMissingApiKeyIsRejectedWithGeneric401(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $response = $this->patchSync($event['id'], $registrationId, ['fields' => ['category' => ['value' => 'M30', 'changedAt' => $this->iso(0)]]], ['X-BZ-Organization-Token' => 'ORG-TOKEN-123']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $this->json($response)['error']['code']);
    }

    public function testRightApiKeyButWrongOrgTokenIsForbidden(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $response = $this->patchSync($event['id'], $registrationId, ['fields' => ['category' => ['value' => 'M30', 'changedAt' => $this->iso(0)]]], [
            'X-API-Key' => self::API_KEY,
            'X-BZ-Organization-Token' => 'not-the-right-token',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testIntegrationDisabledIsForbidden(): void
    {
        $event = $this->createBzEnabledEvent(['bzIntegrationEnabled' => false]);
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $response = $this->patchSync($event['id'], $registrationId, ['fields' => ['category' => ['value' => 'M30', 'changedAt' => $this->iso(0)]]], $this->validHeaders());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testNonexistentEventReturnsGeneric404(): void
    {
        $response = $this->patchSync('does-not-exist', 'does-not-exist', ['fields' => ['category' => ['value' => 'M30', 'changedAt' => $this->iso(0)]]], $this->validHeaders());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function testUnknownRegistrationInAKnownEventReturnsGeneric404(): void
    {
        $event = $this->createBzEnabledEvent();

        $response = $this->patchSync($event['id'], 'does-not-exist', ['fields' => ['category' => ['value' => 'M30', 'changedAt' => $this->iso(0)]]], $this->validHeaders());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testEmptyFieldsPayloadIsRejected(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $response = $this->patchSync($event['id'], $registrationId, ['fields' => []], $this->validHeaders());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $this->json($response)['error']['code']);
    }

    /**
     * The core LWW case: a BZ edit timestamped after the platform's own last
     * touch of that field must be applied.
     */
    public function testNewerBzEditIsAppliedToCompetitorAndRegistrationFields(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId, 'competitorId' => $competitorId] = $this->seedOneFullRegistration($event);

        // +60s, not "now": competitors.updated_at/registrations.updated_at are
        // whole-second timestamps set at seed time above, and the LWW
        // comparison below treats a tie (equal second) as the platform
        // winning (see applyBzFieldSync()'s `>=` — ties are deliberately
        // conservative, never a blind overwrite) — so this test's "clearly
        // newer" edit needs real separation, not just later-in-wall-clock-time.
        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => [
                'last_name' => ['value' => 'Nowak', 'changedAt' => $this->iso(60)],
                'category' => ['value' => 'M30', 'changedAt' => $this->iso(60)],
                'bib_number' => ['value' => '777', 'changedAt' => $this->iso(60)],
            ],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertArrayHasKey('last_name', $data['applied']);
        self::assertArrayHasKey('category', $data['applied']);
        self::assertArrayHasKey('bib_number', $data['applied']);
        self::assertSame([], $data['rejected']);

        $competitor = $this->competitors->find($competitorId);
        self::assertSame('Nowak', $competitor['lastName']);

        $registration = $this->registrations->find($registrationId);
        self::assertSame('M30', $registration['category']);
        self::assertSame('777', $registration['bibNumber']);

        $this->assertLogged($registrationId, 'bz_participant_field_synced', 'Nowak');
    }

    /**
     * The core stale-rejection case: a platform edit (via the ordinary
     * update() path) after BZ's claimed edit timestamp must win, and BZ's
     * incoming edit must be visibly rejected, not silently dropped or
     * silently applied.
     */
    public function testStaleBzEditIsRejectedWhenPlatformEditedItMoreRecently(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        // Platform edits bibNumber "now" (through the ordinary update() path,
        // which stamps registrations.updated_at — see RegistrationRepository::update()).
        $this->registrations->update($registrationId, ['bibNumber' => '100']);

        // BZ's push claims an edit from an hour ago — older than the platform's
        // own edit above, so it must lose.
        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => ['bib_number' => ['value' => '999', 'changedAt' => $this->iso(-3600)]],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([], $data['applied']);
        self::assertArrayHasKey('bib_number', $data['rejected']);
        self::assertSame('stale', $data['rejected']['bib_number']['reason']);

        $registration = $this->registrations->find($registrationId);
        self::assertSame('100', $registration['bibNumber'], 'The platform\'s own newer value must survive untouched.');

        $this->assertLogged($registrationId, 'bz_participant_field_synced', null);
    }

    public function testMissingOrInvalidChangedAtIsRejectedNotAppliedBlindly(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => ['category' => ['value' => 'M30']],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([], $data['applied']);
        self::assertSame('invalid_changed_at', $data['rejected']['category']['reason']);
    }

    /**
     * Defense in depth (issue 12 §5, "bez kwot"): a payment/amount-shaped key
     * must never be accepted from this endpoint, even with a fresh timestamp.
     */
    public function testPaymentShapedFieldsAreAlwaysRejectedAsOutOfScope(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => [
                'pricePaid' => ['value' => 999, 'changedAt' => $this->iso(0)],
                'totalAmount' => ['value' => 999, 'changedAt' => $this->iso(0)],
                'paid' => ['value' => true, 'changedAt' => $this->iso(0)],
            ],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([], $data['applied']);
        foreach (['pricePaid', 'totalAmount', 'paid'] as $key) {
            self::assertSame('field_not_in_scope', $data['rejected'][$key]['reason']);
        }
    }

    /**
     * Only a field explicitly flagged visibleToBz can be return-synced —
     * mirrors bzParticipantList()'s own pull-side filtering (E3.3).
     */
    public function testOnlyBzVisibleCustomFieldCanBeSyncedBack(): void
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

        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => [
                $visibleField['fieldKey'] => ['value' => '+48 000 000 000', 'changedAt' => $this->iso(0)],
                $hiddenField['fieldKey'] => ['value' => 'nie powinno przejsc', 'changedAt' => $this->iso(0)],
            ],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertArrayHasKey($visibleField['fieldKey'], $data['applied']);
        self::assertSame('field_not_in_scope', $data['rejected'][$hiddenField['fieldKey']]['reason']);
    }

    /**
     * E3.6 (issue 12 §4): the core new-field case — a fresh check-in report
     * from BZ (both checked_in_at and checked_in_by) must apply, be visible
     * on the plain registration read afterwards, and be logged.
     */
    public function testCheckInStatusFromBzIsAppliedAndPersisted(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $checkedInAt = $this->iso(60);
        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => [
                'checked_in_at' => ['value' => $checkedInAt, 'changedAt' => $this->iso(60)],
                'checked_in_by' => ['value' => 'Jan Kowalski (BZ)', 'changedAt' => $this->iso(60)],
            ],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertArrayHasKey('checked_in_at', $data['applied']);
        self::assertArrayHasKey('checked_in_by', $data['applied']);
        self::assertSame([], $data['rejected']);

        $registration = $this->registrations->find($registrationId);
        self::assertNotNull($registration['checkedInAt']);
        self::assertSame('Jan Kowalski (BZ)', $registration['checkedInBy']);

        $this->assertLogged($registrationId, 'bz_participant_field_synced', 'checked_in_by');
    }

    /**
     * checked_in_at follows the exact same LWW rule as the other
     * registration-level fields (registrations.updated_at) — a platform edit
     * made more recently than BZ's claimed check-in instant must win, and
     * BZ's push must show up as explicitly rejected.
     */
    public function testStaleCheckInReportIsRejected(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $this->registrations->update($registrationId, ['bibNumber' => '55']);

        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => ['checked_in_at' => ['value' => $this->iso(-3600), 'changedAt' => $this->iso(-3600)]],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([], $data['applied']);
        self::assertSame('stale', $data['rejected']['checked_in_at']['reason']);

        $registration = $this->registrations->find($registrationId);
        self::assertNull($registration['checkedInAt']);
    }

    /**
     * checked_in_at is a timestamp column — an unparseable value must be
     * rejected rather than stored as opaque garbage.
     */
    public function testUnparseableCheckInTimestampIsRejectedAsInvalidValue(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $response = $this->patchSync($event['id'], $registrationId, [
            'fields' => ['checked_in_at' => ['value' => 'not-a-timestamp', 'changedAt' => $this->iso(0)]],
        ], $this->validHeaders());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([], $data['applied']);
        self::assertSame('invalid_value', $data['rejected']['checked_in_at']['reason']);
    }

    /**
     * BZ can explicitly clear a mistaken check-in by sending checked_in_at:
     * null with a fresh changedAt — a legitimate edit, not a value that fails
     * "must parse as a timestamp".
     */
    public function testExplicitNullClearsAPreviousCheckIn(): void
    {
        $event = $this->createBzEnabledEvent();
        ['registrationId' => $registrationId] = $this->seedOneFullRegistration($event);

        $first = $this->patchSync($event['id'], $registrationId, [
            'fields' => ['checked_in_at' => ['value' => $this->iso(60), 'changedAt' => $this->iso(60)]],
        ], $this->validHeaders());
        self::assertSame(200, $first->getStatusCode());
        self::assertNotNull($this->registrations->find($registrationId)['checkedInAt']);

        $second = $this->patchSync($event['id'], $registrationId, [
            'fields' => ['checked_in_at' => ['value' => null, 'changedAt' => $this->iso(120)]],
        ], $this->validHeaders());

        self::assertSame(200, $second->getStatusCode());
        $data = $this->json($second)['data'];
        self::assertArrayHasKey('checked_in_at', $data['applied']);
        self::assertNull($this->registrations->find($registrationId)['checkedInAt']);
    }

    private function assertLogged(string $registrationId, string $action, ?string $descriptionContains): void
    {
        $logs = $this->admin->activityLogs(['targetType' => 'registration', 'targetId' => $registrationId, 'action' => $action]);
        self::assertNotEmpty($logs, 'Expected an activity_logs entry for this registration.');
        $entry = $logs[0];
        self::assertNotSame('', trim((string) $entry['userId']));
        self::assertNotEquals('', $entry['description']);
        // Actor must be identifiable as Biuro Zawodów, not a platform user — never a JWT `sub`.
        self::assertStringContainsStringIgnoringCase('biuro zawod', $entry['description']);
        if ($descriptionContains !== null) {
            self::assertStringContainsString($descriptionContains, $entry['description']);
        }
    }

    private function patchSync(string $eventId, string $registrationId, array $body, array $headers): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('PATCH', '/api/v1/events/' . $eventId . '/bz/participants/' . $registrationId, $body, $headers);
    }

    private function validHeaders(): array
    {
        return ['X-API-Key' => self::API_KEY, 'X-BZ-Organization-Token' => 'ORG-TOKEN-123'];
    }

    private function iso(int $offsetSeconds): string
    {
        return (new \DateTimeImmutable())->modify($offsetSeconds . ' seconds')->format(DATE_ATOM);
    }

    /**
     * @return array{id: string, slug: string, bzOrganizationToken: ?string, bzIntegrationEnabled: bool}
     */
    private function createBzEnabledEvent(array $overrides = []): array
    {
        $slug = $this->prefix . '-event-' . bin2hex(random_bytes(3));
        return $this->events->create(array_replace([
            'name' => 'PHPUnit BZ Sync Event',
            'slug' => $slug,
            'discipline' => 'running',
            'date' => '2026-09-20',
            'location' => 'Stadion Testowy',
            'city' => 'Warszawa',
            'description' => '<p>Opis testowy</p>',
            'imageUrl' => 'https://example.test/event.jpg',
            'organizerId' => 'phpunit-bz-sync-organizer',
            'organizerName' => 'PHPUnit BZ Sync Organizer',
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
    }

    /** @return array{registrationId: string, competitorId: string} */
    private function seedOneFullRegistration(array $event): array
    {
        $competitor = $this->competitors->create([
            'firstName' => 'PHPUnit BZ Sync Runner',
            'lastName' => 'Testowy',
        ], skipDuplicateCheck: true);

        $registration = $this->registrations->create([
            'eventId' => $event['id'],
            'eventName' => $event['name'],
            'competitorId' => $competitor['id'],
            'userId' => 'phpunit-bz-sync-user-' . bin2hex(random_bytes(4)),
            'userName' => 'PHPUnit BZ Sync Runner Testowy',
            'userEmail' => 'phpunit-bz-sync-' . bin2hex(random_bytes(4)) . '@example.test',
            'distanceId' => $event['distances'][0]['id'],
            'distanceName' => $event['distances'][0]['name'],
            'category' => 'Open',
        ]);

        return [
            'registrationId' => (string) $registration['id'],
            'competitorId' => (string) $competitor['id'],
        ];
    }
}
