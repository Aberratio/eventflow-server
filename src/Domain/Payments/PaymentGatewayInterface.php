<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Payments;

/**
 * Trasa A (bramka) seam RegistrationRepository codes against — deliberately not
 * "TpayGateway" directly, so a second gateway (PayNow, E1.7) can implement this
 * same contract without RegistrationRepository's create()/retryPayment()/
 * initiateGatewayRefund() changing shape. Both Tpay\TpayGateway (E1.6) and
 * PayNow\PayNowGateway (E1.7) implement it — RegistrationRepository takes one
 * instance of each (constructor's $gateway and $payNowGateway) and picks
 * between them per Wydarzenie via payment_config.provider; see
 * RegistrationRepository::gatewayForProvider().
 */
interface PaymentGatewayInterface
{
    /**
     * Starts a new payment session for one Zapis and returns where to send the
     * payer to complete it. `$amount` must always be the caller's already-frozen
     * Rozliczenie total (RegistrationRepository::totalAmountFor()) — this
     * interface takes a plain float precisely so nothing upstream of the caller
     * can smuggle a client-supplied amount in as anything richer.
     *
     * @return array{transactionId: string, redirectUrl: string}
     */
    public function startSession(
        string $registrationId,
        float $amount,
        string $description,
        string $payerEmail,
        string $payerName
    ): array;

    /** Issues a real refund at the gateway for a previously paid transaction. */
    public function refund(string $transactionId, float $amount): void;
}
