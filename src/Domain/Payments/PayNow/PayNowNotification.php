<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments\PayNow;

/**
 * A parsed, normalized PayNow webhook notification
 * (https://docs.paynow.pl/docs/v1/integration — "Odbieranie powiadomień"). The
 * body is always JSON with exactly four fields: `paymentId`, `externalId`,
 * `status`, `modifiedAt`. Unlike Tpay's notification, PayNow's carries no
 * amount at all — RegistrationRepository::applyGatewayNotification()'s
 * `$paidAmount` parameter is unused by its own implementation today (the paid
 * amount of record is always the frozen Rozliczenie total the session was
 * opened with, in registration_gateway_transactions), so this is passed as
 * 0.0 from the webhook route rather than invented from nothing — see
 * routes.php.
 */
final class PayNowNotification
{
    /** Paynow's own documented payment statuses; only CONFIRMED means paid. */
    private const PAID_STATUS = 'CONFIRMED';

    public function __construct(
        public readonly string $transactionId,
        public readonly bool $paid,
        public readonly ?string $registrationId,
    ) {
    }

    public static function fromRawBody(string $rawBody): self
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('paynow_notification_malformed_body');
        }

        $transactionId = (string) ($decoded['paymentId'] ?? '');
        if ($transactionId === '') {
            throw new \RuntimeException('paynow_notification_missing_payment_id');
        }

        $status = strtoupper(trim((string) ($decoded['status'] ?? '')));
        $registrationId = (string) ($decoded['externalId'] ?? '');

        return new self($transactionId, $status === self::PAID_STATUS, $registrationId !== '' ? $registrationId : null);
    }
}
