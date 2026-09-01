<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\Tpay;

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
        public readonly float $amount,
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

        $amount = (float) ($fields['transactionAmount'] ?? $fields['tr_amount'] ?? 0);
        $registrationId = (string) ($fields['transactionHiddenDescription'] ?? $fields['tr_crc'] ?? '');

        return new self($transactionId, $paid, $amount, $registrationId !== '' ? $registrationId : null);
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
