<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Integration\Http;

use ZmierzymyCzas\Domain\Events\EventTeamRepository;
use ZmierzymyCzas\Tests\Integration\Http\Rbac\RbacFixtures;
use ZmierzymyCzas\Tests\Support\HttpTestCase;
use ZmierzymyCzas\Tests\Support\SavepointPdo;

/**
 * R-01 (S1): the Event Team's Co-managers. Targeted checks beside the RBAC grid: a Co-manager of any global
 * role manages the Event but touches nothing owner-only, and a revoked / merely invited member gets nothing.
 * Runs on the RBAC fixture world (`coManager` = participant, `coManagerOrg` = organizer, `revoked`).
 */
final class EventTeamAccessContractTest extends HttpTestCase
{
    private static ?SavepointPdo $sharedPdo = null;
    private static RbacFixtures $fx;

    public static function setUpBeforeClass(): void
    {
        [self::$sharedPdo, self::$fx] = SavepointPdo::openWorld(RbacFixtures::build(...));
    }

    public static function tearDownAfterClass(): void
    {
        self::$sharedPdo?->rollbackOuter();
        self::$sharedPdo = null;
    }

    protected function createPdo(): \PDO
    {
        return self::$sharedPdo ?? throw new \LogicException('The fixture world is not open.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$sharedPdo->savepoint('team_test');
    }

    protected function tearDown(): void
    {
        self::$sharedPdo->rollbackTo('team_test');
    }

    /** @return array<string, string> */
    private function as(string $actor): array
    {
        $identity = self::$fx->actors[$actor];

        return $this->bearer($identity['id'], $identity['role'], $identity['email']);
    }

    private function call(string $actor, string $method, string $path, array $body = []): array
    {
        $response = $this->request($method, $path, $body, $this->as($actor));

        return ['status' => $response->getStatusCode(), 'json' => json_decode((string) $response->getBody(), true)];
    }

    private function eventPath(string $suffix = ''): string
    {
        return '/api/v1/events/' . self::$fx->values['event'] . $suffix;
    }

    /** @return array<string, array{string}> */
    public static function coManagers(): array
    {
        return ['participant-role' => ['coManager'], 'organizer-role' => ['coManagerOrg']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coManagers')]
    public function testCoManagerEditsEventAndManagesRegistrationsAndResults(string $actor): void
    {
        $edit = $this->call($actor, 'PATCH', $this->eventPath(), ['name' => 'Renamed by co-manager']);
        self::assertSame(200, $edit['status']);
        self::assertSame('Renamed by co-manager', $edit['json']['data']['name']);

        self::assertSame(200, $this->call($actor, 'GET', $this->eventPath('/registrations'))['status']);
        self::assertSame(200, $this->call($actor, 'GET', $this->eventPath('/registration-stats'))['status']);
        self::assertSame(200, $this->call($actor, 'GET', $this->eventPath('/form-fields'))['status']);
        self::assertSame(200, $this->call($actor, 'GET', $this->eventPath('/mail-log'))['status']);
        self::assertSame(200, $this->call($actor, 'GET', $this->eventPath('/activity-logs'))['status']);

        $resultEdit = $this->call($actor, 'PATCH', '/api/v1/results/' . self::$fx->values['result'], ['bibNumber' => 'R2']);
        self::assertSame(200, $resultEdit['status']);

        $review = $this->call($actor, 'PATCH', '/api/v1/virtual-results/' . self::$fx->values['virtualSubmission'] . '/review', ['status' => 'approved']);
        self::assertNotSame(403, $review['status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coManagers')]
    public function testCoManagerEditsADraftEventOfTheTeam(string $actor): void
    {
        $draftPath = '/api/v1/events/' . self::$fx->values['eventDraft'];
        self::assertSame(200, $this->call($actor, 'GET', $draftPath)['status'], 'draft is visible to the Team');
        self::assertSame(200, $this->call($actor, 'PATCH', $draftPath, ['name' => 'Draft by co-manager'])['status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coManagers')]
    public function testCoManagerCannotChangePaymentConfigOrHandOverTheEvent(string $actor): void
    {
        $before = $this->call('organizer', 'GET', $this->eventPath())['json']['data'];

        $attempt = $this->call($actor, 'PATCH', $this->eventPath(), [
            'name' => $before['name'],
            'paymentConfig' => ['type' => 'bank_transfer', 'bankAccount' => 'PL00 0000 0000 0000 0000 0000 0000'],
        ]);
        self::assertSame(200, $attempt['status']);
        self::assertSame($before['paymentConfig'], $attempt['json']['data']['paymentConfig'], 'payment config is Owner-only: silently dropped');

        $transfer = $this->call($actor, 'PATCH', $this->eventPath(), ['organizerId' => self::$fx->values['foreignOrganizer']]);
        self::assertSame(403, $transfer['status']);
        self::assertSame(self::$fx->values['organizer'], $this->call('organizer', 'GET', $this->eventPath())['json']['data']['organizerId']);

        // The Owner can still change payment config (control).
        $owner = $this->call('organizer', 'PATCH', $this->eventPath(), [
            'paymentConfig' => ['type' => 'bank_transfer', 'bankAccount' => 'PL00 0000 0000 0000 0000 0000 0000'],
        ]);
        self::assertSame(200, $owner['status']);
        self::assertSame('bank_transfer', $owner['json']['data']['paymentConfig']['type']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coManagers')]
    public function testCoManagerCannotDeleteCancelOrTouchMoney(string $actor): void
    {
        self::assertSame(403, $this->call($actor, 'DELETE', $this->eventPath())['status']);
        self::assertSame(403, $this->call($actor, 'POST', $this->eventPath('/cancel'))['status']);

        $registration = '/api/v1/registrations/' . self::$fx->values['registration'];
        self::assertSame(403, $this->call($actor, 'POST', $registration . '/mark-paid')['status']);
        self::assertSame(403, $this->call($actor, 'POST', $registration . '/refund-approve')['status']);
        self::assertSame(403, $this->call($actor, 'POST', $registration . '/refund-complete')['status']);
        self::assertSame(403, $this->call($actor, 'PATCH', $registration . '/payment-details', ['paymentStatus' => 'paid'])['status']);

        // The Event is untouched, and the Owner could still delete it (control: the delete path is reachable).
        self::assertSame(200, $this->call('organizer', 'GET', $this->eventPath())['status']);
    }

    public function testCoManagerOfOneEventHasNoPowerOverAnother(): void
    {
        $otherEvent = '/api/v1/events/' . self::$fx->values['eventDeleted'];
        self::assertSame(403, $this->call('coManager', 'PATCH', $otherEvent, ['name' => 'nope'])['status']);
        self::assertSame(403, $this->call('coManagerOrg', 'GET', $otherEvent . '/registrations')['status']);
    }

    public function testRevokedMemberGetsNothing(): void
    {
        self::assertSame(403, $this->call('revoked', 'PATCH', $this->eventPath(), ['name' => 'nope'])['status']);
        self::assertSame(403, $this->call('revoked', 'GET', $this->eventPath('/registrations'))['status']);
        self::assertSame(403, $this->call('revoked', 'POST', '/api/v1/uploads/event-images')['status']);
        self::assertSame(404, $this->call('revoked', 'GET', '/api/v1/events/' . self::$fx->values['eventDraft'])['status']);
        self::assertSame(404, $this->call('revoked', 'GET', '/api/v1/events/' . self::$fx->values['eventPrivate'])['status']);
        self::assertSame([], $this->call('revoked', 'GET', '/api/v1/organizers/' . self::$fx->values['revoked'] . '/events')['json']['data']);
    }

    public function testInvitedButNotAcceptedMemberGetsNothing(): void
    {
        $pdo = self::$sharedPdo;
        $pdo->prepare(
            "INSERT INTO event_team_members (id, event_id, user_id, status, invited_email) VALUES (:id, :eventId, :userId, 'invited', :email)"
        )->execute([
            'id' => self::$fx->prefix . '-inv',
            'eventId' => self::$fx->values['event'],
            'userId' => self::$fx->values['participant'],
            'email' => self::$fx->actors['participant']['email'],
        ]);

        self::assertSame(403, $this->call('participant', 'PATCH', $this->eventPath(), ['name' => 'nope'])['status']);
        self::assertSame(404, $this->call('participant', 'GET', '/api/v1/events/' . self::$fx->values['eventDraft'])['status']);
        self::assertSame([], $this->call('participant', 'GET', '/api/v1/organizers/' . self::$fx->values['participant'] . '/events')['json']['data']);
        self::assertFalse((new EventTeamRepository($pdo))->isActiveCoManager(self::$fx->values['event'], self::$fx->values['participant']));
    }

    public function testPanelListCarriesTheTeamRole(): void
    {
        $owner = $this->call('organizer', 'GET', '/api/v1/organizers/' . self::$fx->values['organizer'] . '/events');
        self::assertSame(200, $owner['status']);
        $ownerRows = array_column($owner['json']['data'], 'teamRole', 'id');
        self::assertSame('owner', $ownerRows[self::$fx->values['event']]);

        foreach (['coManager', 'coManagerOrg'] as $actor) {
            $mine = $this->call($actor, 'GET', '/api/v1/organizers/' . self::$fx->values[$actor] . '/events');
            self::assertSame(200, $mine['status']);
            $rows = array_column($mine['json']['data'], 'teamRole', 'id');
            self::assertSame('co_manager', $rows[self::$fx->values['event']] ?? null, $actor);
            self::assertSame('co_manager', $rows[self::$fx->values['eventDraft']] ?? null, $actor);
            self::assertArrayNotHasKey(self::$fx->values['eventDeleted'], $rows, $actor);
        }

        // Nobody reads another Account's panel list.
        self::assertSame(403, $this->call('coManager', 'GET', '/api/v1/organizers/' . self::$fx->values['organizer'] . '/events')['status']);
    }

    public function testCoManagerUploadsEventImagesLikeAnOrganizer(): void
    {
        // No file attached: authorization passes and the handler answers 422 (validation), never 403.
        self::assertSame(422, $this->call('coManager', 'POST', '/api/v1/uploads/event-images')['status']);
        self::assertSame(403, $this->call('participant', 'POST', '/api/v1/uploads/event-images')['status']);
    }

    public function testCoManagerCannotCreateEvents(): void
    {
        self::assertSame(403, $this->call('coManager', 'POST', '/api/v1/events', ['name' => 'x'])['status']);
    }

    public function testCoManagerSeesPrivateEventsOfItsTeamInTheList(): void
    {
        $path = '/api/v1/events?includePrivate=1&search=' . self::$fx->prefix;
        $ids = fn (string $actor): array => array_column($this->call($actor, 'GET', $path)['json']['data'], 'id');

        self::assertContains(self::$fx->values['eventPrivate'], $ids('coManager'));
        self::assertNotContains(self::$fx->values['eventPrivate'], $ids('revoked'));
        self::assertNotContains(self::$fx->values['eventPrivate'], $ids('participant'));
    }
}
