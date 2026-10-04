<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Domain\Events\EventRepository;
use ZmierzymyCzas\Domain\Events\PublicEventPresenter;

final class PublicEventPresenterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fullEvent(): array
    {
        $event = [];
        foreach ([...PublicEventPresenter::PUBLIC_KEYS, ...PublicEventPresenter::WITHHELD_KEYS] as $key) {
            $event[$key] = 'v-' . $key;
        }
        $event['accessCode'] = 'secret-code';
        $event['bzOrganizationToken'] = 'ORG-TOKEN';
        $event['futureSecretColumn'] = 'leak';
        $event['displayOptions'] = ['showFreeSlots' => true];
        $event['distances'] = [['id' => 'd1', 'name' => '10 km', 'slotsRemaining' => 7]];
        $event['discountCodes'] = [[
            'id' => 'dc1', 'code' => 'PP55', 'discountType' => 'percent', 'discountValue' => 10, 'active' => true,
            'distanceIds' => ['d1'], 'usedCount' => 1,
            'usedBy' => [['userId' => 'u1', 'userName' => 'Jan Tajny', 'registrationId' => 'r1']],
        ]];

        return $event;
    }

    public function testCredentialKeysAreAlwaysNull(): void
    {
        $out = PublicEventPresenter::present($this->fullEvent());
        self::assertArrayHasKey('accessCode', $out);
        self::assertNull($out['accessCode']);
        self::assertArrayHasKey('bzOrganizationToken', $out);
        self::assertNull($out['bzOrganizationToken']);

        $bare = PublicEventPresenter::present(['id' => 'e1']);
        self::assertArrayHasKey('accessCode', $bare);
        self::assertNull($bare['accessCode']);
        self::assertNull($bare['bzOrganizationToken']);
    }

    public function testManagerOnlyKeysAreDropped(): void
    {
        $out = PublicEventPresenter::present($this->fullEvent());
        foreach (PublicEventPresenter::WITHHELD_KEYS as $key) {
            self::assertArrayNotHasKey($key, $out, $key);
        }
    }

    public function testUnknownKeysAreDropped(): void
    {
        self::assertArrayNotHasKey('futureSecretColumn', PublicEventPresenter::present($this->fullEvent()));
    }

    public function testDiscountCodesKeepPricingFieldsButNotUsedBy(): void
    {
        $code = PublicEventPresenter::present($this->fullEvent())['discountCodes'][0];
        self::assertArrayNotHasKey('usedBy', $code);
        self::assertSame('PP55', $code['code']);
        self::assertSame('percent', $code['discountType']);
        self::assertSame(10, $code['discountValue']);
        self::assertTrue($code['active']);
        self::assertSame(['d1'], $code['distanceIds']);
    }

    public function testShowFreeSlotsFalseHidesSlotsRemaining(): void
    {
        $event = $this->fullEvent();
        $event['displayOptions'] = ['showFreeSlots' => false];
        $out = PublicEventPresenter::present($event);
        self::assertArrayNotHasKey('slotsRemaining', $out['distances'][0]);
        self::assertSame('10 km', $out['distances'][0]['name']);
    }

    public function testShowFreeSlotsDefaultKeepsSlotsRemaining(): void
    {
        $event = $this->fullEvent();
        self::assertSame(7, PublicEventPresenter::present($event)['distances'][0]['slotsRemaining']);
        $event['displayOptions'] = null;
        self::assertSame(7, PublicEventPresenter::present($event)['distances'][0]['slotsRemaining']);
    }

    public function testEveryEventRepositoryColumnIsClassified(): void
    {
        $columns = (new \ReflectionClassConstant(EventRepository::class, 'EVENT_COLUMNS'))->getValue();
        $keys = [];
        foreach (explode(',', (string) $columns) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $keys[] = stripos($part, ' AS ') !== false ? trim(substr($part, stripos($part, ' AS ') + 4)) : $part;
        }
        $keys = [...$keys, 'distances', 'paymentMethods', 'merchandise', 'discountCodes', 'galleryAlbums', 'resultDocuments', 'documents', 'insuranceVariants'];

        $known = [...PublicEventPresenter::PUBLIC_KEYS, ...PublicEventPresenter::NULLED_KEYS, ...PublicEventPresenter::WITHHELD_KEYS];
        self::assertSame([], array_values(array_diff($keys, $known)), 'Classify every new Event column in PublicEventPresenter.');

        self::assertSame([], array_intersect(PublicEventPresenter::PUBLIC_KEYS, PublicEventPresenter::NULLED_KEYS));
        self::assertSame([], array_intersect(PublicEventPresenter::PUBLIC_KEYS, PublicEventPresenter::WITHHELD_KEYS));
        self::assertSame([], array_intersect(PublicEventPresenter::NULLED_KEYS, PublicEventPresenter::WITHHELD_KEYS));
    }
}
