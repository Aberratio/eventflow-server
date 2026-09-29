<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/**
 * PP.52: pure payload policy for `POST /registrations`. The caller only picks
 * WHAT to register for (event, distance, variant, team, extras) and optionally
 * WHICH Zawodnik (competitorId). Identity (userId/userName/userEmail/
 * registeredById) is derived server-side from the JWT and the Zawodnik, and a
 * Zapis's payment status is set only by the payment state machine (webhook,
 * mark-paid) — never claimed by the caller (same rule as PP.2 for PATCH).
 */
final class RegistrationCreatePolicy
{
    /** Forwarded to RegistrationRepository::create(); every other key is dropped. */
    public const SELECTION_FIELDS = [
        'eventId', 'distanceId', 'category', 'competitorId',
        'teamName', 'teamId', 'createTeam', 'specialCategory',
        'pricingVariantId', 'discountCode', 'merchandise', 'merchandiseOrders',
        'insuranceVariantId',
    ];

    /** Flags inside `paymentDetails` that assert a payment happened. */
    public const PAID_FLAGS = ['startFeePaid', 'insurancePaid'];

    /**
     * @param array<string, mixed> $payload
     * @return array{allowed: array<string, mixed>, claimedUserId: ?string, paidClaim: bool}
     */
    public static function filter(array $payload): array
    {
        $allowed = [];
        foreach (self::SELECTION_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $allowed[$field] = $payload[$field];
            }
        }

        $claimedUserId = isset($payload['userId']) && (string) $payload['userId'] !== ''
            ? (string) $payload['userId']
            : null;

        return [
            'allowed' => $allowed,
            'claimedUserId' => $claimedUserId,
            'paidClaim' => self::claimsPayment($payload['paymentDetails'] ?? null),
        ];
    }

    /** True when `paymentDetails` asserts any payment (a paid flag, or a merchandisePayments[*].paid). */
    public static function claimsPayment(mixed $paymentDetails): bool
    {
        if ($paymentDetails === null) {
            return false;
        }
        if (!is_array($paymentDetails)) {
            return (bool) $paymentDetails;
        }
        foreach (self::PAID_FLAGS as $flag) {
            if (!empty($paymentDetails[$flag])) {
                return true;
            }
        }
        $merchandisePayments = $paymentDetails['merchandisePayments'] ?? null;
        if (is_array($merchandisePayments)) {
            foreach ($merchandisePayments as $payment) {
                if (is_array($payment) && !empty($payment['paid'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
