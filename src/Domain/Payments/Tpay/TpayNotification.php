<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Tpay;

use ZmierzymyCzas\Domain\Payments\GatewayAmount;

/**
 * A parsed, normalized Tpay webhook notification — the shape the rest of this
 * codebase deals with, independent of whether Tpay sent it as the Transactions
 * API's JSON body (`transactionId`/`transactionStatus`/...) or the classic
 * marketplace form-encoded body (`tr_id`/`tr_status`/...). Both exist in Tpay's
 * own documentation; this integration accepts either shape so it isn't brittle to
 * which one a given sandbox/production account is configured to send.
 */
final class TpayNotification
{
    public function __construct(
        public readonly string $transactionId,
        public readonly bool $paid,
        public readonly ?int $paidAmountGrosze,
        public readonly ?string $registrationId,
    ) {
    }

    public static function fromRawBody(string $rawBody, string $contentType): self
    {
        $fields = self::decode($rawBody, $contentType);

        $transactionId = (string) ($fields['transactionId'] ?? $fields['tr_id'] ?? '');
        if ($transactionId === '') {
            throw new \RuntimeException('tpay_notification_missing_transaction_id');
        }

        $statusValue = strtolower(trim((string) ($fields['transactionStatus'] ?? $fields['tr_status'] ?? '')));
        // Tpay's documented success markers across both notification shapes:
        // the Transactions API sends "correct", the classic marketplace API sends
        // the literal string "true" for tr_status. Anything else (pending states,
        // "chargeback", "error", ...) is treated as not-yet-paid.
        $paid = in_array($statusValue, ['correct', 'true', 'paid', 'completed'], true);

        $paidAmountGrosze = self::paidAmountGrosze($fields);
        $registrationId = (string) ($fields['transactionHiddenDescription'] ?? $fields['tr_crc'] ?? '');

        return new self($transactionId, $paid, $paidAmountGrosze, $registrationId !== '' ? $registrationId : null);
    }

    /**
     * The amount the gateway reports as paid, in integer grosze, or null when it is
     * absent or malformed. Takes the first present, non-empty key of tr_paid,
     * transactionPaidAmount, tr_amount, transactionAmount (tr_paid first: the classic
     * notification documents it as the amount actually paid, which differs from the
     * target tr_amount on overpay/surcharge). A malformed first-present value yields
     * null — it never falls back to a later key. The field names are unverified
     * against a real account.
     *
     * @param array<string, mixed> $fields
     */
    private static function paidAmountGrosze(array $fields): ?int
    {
        foreach (['tr_paid', 'transactionPaidAmount', 'tr_amount', 'transactionAmount'] as $key) {
            $value = $fields[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            return GatewayAmount::toGrosze($value);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function decode(string $rawBody, string $contentType): array
    {
        if (str_contains($contentType, 'application/json') || (trim($rawBody) !== '' && $rawBody[0] === '{')) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        parse_str($rawBody, $fields);
        return $fields;
    }
}
