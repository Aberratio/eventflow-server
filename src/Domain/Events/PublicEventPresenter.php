<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Events;

/**
 * PP.55: the one shape an Event may have when it leaves the server to anyone who is not
 * its manager ($canManageEvent) - guests, participants, foreign organizers, public feeds.
 * Allowlist: a key that is not listed here (including any column added later) is dropped.
 */
final class PublicEventPresenter
{
    /** Top-level keys a non-manager reader may receive. */
    public const PUBLIC_KEYS = [
        'id', 'name', 'slug', 'discipline', 'date', 'location', 'locationMapsUrl', 'city', 'description',
        'imageUrl', 'originalImageUrl', 'seriesId',
        'organizerId', 'organizerName', 'organizerContact', 'organizerInfo', 'organizersHtml',
        'organizerLogoUrl', 'originalOrganizerLogoUrl', 'organizerLogoShape',
        'publicationStatus', 'visibility', 'isPrivate', 'isTest', 'isExternal',
        'registrationDeadline', 'registrationOpenDate', 'registrationUntilEventStart', 'registrationStatus',
        'featured', 'liveResultsUrl', 'isLive',
        'routeMapUrl', 'routeGpxUrl', 'routeElevationProfile',
        'bannerUrl', 'originalBannerUrl', 'bannerCropArea', 'backgroundId',
        'isVirtual', 'startDate', 'endDate',
        'paymentConfig', 'rulesHtml', 'finalAcceptanceHtml', 'requireFinalAcceptance',
        'insuranceOption', 'insuranceVariants', 'bzIntegrationEnabled', 'enableMerchandiseInRegistration',
        'registrationEmbed', 'registrationFormConfig', 'embedEnabled', 'embedAllowedOrigins',
        'displayOptions', 'logisticsInfo',
        'distances', 'paymentMethods', 'merchandise', 'discountCodes', 'galleryAlbums', 'resultDocuments', 'documents',
    ];

    /** Credential-shaped keys: always present, always null (E3.2 response shape; client null-compare). */
    public const NULLED_KEYS = ['accessCode', 'bzOrganizationToken'];

    /** Manager-only keys, dropped. Documentation + the completeness unit test; not used at runtime. */
    public const WITHHELD_KEYS = [
        'deletedAt', 'rejectionReason', 'submittedAt', 'approvedAt', 'approvedBy',
        'publishedAt', 'publishedBy', 'createdBy', 'eventCreditKind', 'googleCalendarEventId',
    ];

    /** Per discount code: everything the client price preview needs (pricing.ts), never `usedBy` (PII). */
    public const DISCOUNT_CODE_KEYS = [
        'id', 'code', 'discountType', 'discountValue', 'validFrom', 'validTo', 'maxUses', 'usedCount', 'distanceIds', 'active',
    ];

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function present(array $event): array
    {
        $out = array_intersect_key($event, array_flip(self::PUBLIC_KEYS));
        foreach (self::NULLED_KEYS as $key) {
            $out[$key] = null;
        }
        if (isset($out['discountCodes']) && is_array($out['discountCodes'])) {
            $allowed = array_flip(self::DISCOUNT_CODE_KEYS);
            $out['discountCodes'] = array_map(
                static fn (array $code): array => array_intersect_key($code, $allowed),
                array_values(array_filter($out['discountCodes'], 'is_array'))
            );
        }
        // E8.2 (moved verbatim from routes.php $stripBzToken): showFreeSlots=false hides slotsRemaining.
        $display = is_array($event['displayOptions'] ?? null) ? $event['displayOptions'] : [];
        $showFreeSlots = ($display['showFreeSlots'] ?? true) !== false;
        if (!$showFreeSlots && !empty($out['distances']) && is_array($out['distances'])) {
            $out['distances'] = array_map(static function (array $distance): array {
                unset($distance['slotsRemaining']);
                return $distance;
            }, $out['distances']);
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function presentList(array $events): array
    {
        return array_values(array_map([self::class, 'present'], $events));
    }
}
