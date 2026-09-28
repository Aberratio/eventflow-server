<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/**
 * PP.2: pure whitelist policy for `PATCH /registrations/{id}`. No DB access —
 * the HTTP handler decides `$isManager` via `$authorizeRegistration()` first.
 *
 * `status`, `discountCode`, `removedAt`, `teamId`, `paymentDetails`, `merchandise`
 * and `merchandiseOrders` are never accepted through this route for anyone: each
 * one either has its own validated path (payment state machine, cancel,
 * refund-*, mark-paid, webhooks, payment-details, team join) or should not be
 * writable at all from a generic PATCH.
 */
final class RegistrationPatchPolicy
{
    /** Registration owner (non-manager): only the free-text team/club label. */
    public const OWNER_EDITABLE = ['teamName'];

    /** Event manager (own event organizer / staff per $canManageEvent). */
    public const MANAGER_EDITABLE = ['bibNumber', 'teamName', 'checkedInAt', 'checkedInBy'];

    /**
     * @param array<string, mixed> $payload
     * @return array{allowed: array<string, mixed>, rejected: list<string>}
     */
    public static function filter(array $payload, bool $isManager): array
    {
        $allowedKeys = $isManager ? self::MANAGER_EDITABLE : self::OWNER_EDITABLE;

        $allowed = [];
        $rejected = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                $allowed[$key] = $value;
                continue;
            }
            $rejected[] = (string) $key;
        }

        return ['allowed' => $allowed, 'rejected' => $rejected];
    }
}
