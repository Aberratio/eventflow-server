<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CriticalMails;

use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerConfig;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxService;

/**
 * The full 15-item critical mail catalog (17-katalog-maili-i-kontrakt-z-mailerem.md
 * §1), one typed method per mail type, built on top of E1.9's
 * CentralMailerOutboxService. This is the only class in the codebase that
 * knows how to turn "registration X just got paid" into an actual outbox row:
 * resolve the template (MailTemplateRepository — organizer override, else
 * global default, else MailCatalog's built-in default), render it
 * (MailRenderer — closed-list substitution), build the deterministic
 * idempotency key (§6: `{sourceEntityId}:{mailType}:v{templateVersion}`,
 * never a timestamp or random suffix), and enqueue.
 *
 * Skips silently (returns null, no exception) whenever Central Mailer isn't
 * configured — mirrors TpayConfig::isConfigured()/PayNowConfig::isConfigured()
 * and E1.9's own CentralMailerConfig::isConfigured() doc comment: "do not
 * attempt to send" rather than a request that could only ever fail. Also
 * skips silently when $recipientEmail is blank (e.g. a Zawodnik with no
 * Konto email of their own) — nothing to send to.
 *
 * Reply-to (17 §4): `from` is always Central Mailer's configured platform
 * address; `reply-to` should be the organizer's contact for event-scoped
 * mails (4, 5, 7, 8, 9, 10) and platform support otherwise (1-3, 12). Left
 * entirely unimplemented here on purpose — CentralMailerClientInterface::enqueue()
 * has no `from`/`replyTo` field at all, and kontrakt-central-mailer.md's own
 * backlog list (04 §"Braki") names per-request from/replyTo as a confirmed gap
 * in Central Mailer itself, not something this platform can render around via
 * HTML headers. Revisit once Central Mailer's API grows that field.
 */
final class CriticalMailer
{
    public function __construct(
        private readonly CentralMailerConfig $config,
        private readonly CentralMailerOutboxService $outbox,
        private readonly MailTemplateRepository $templates,
        private readonly MailRenderer $renderer = new MailRenderer(),
    ) {
    }

    // --- 1-3: uwierzytelnianie (technical queue, systemic templates) ---------

    /**
     * @param string $sourceEntityId must be unique per mail instance (the idempotency key is built from it),
     *                               e.g. the action-token id; a user id would send only the first mail ever
     */
    public function sendEmailVerification(string $sourceEntityId, string $email, string $verificationLink): ?array
    {
        return $this->send(MailCatalog::EMAIL_VERIFICATION, $sourceEntityId, null, $email, [
            'verificationLink' => $verificationLink,
        ]);
    }

    /**
     * @param string $sourceEntityId must be unique per mail instance, e.g. the action-token id (see sendEmailVerification)
     */
    public function sendPasswordReset(string $sourceEntityId, string $email, string $resetLink, int $expiresInMinutes): ?array
    {
        return $this->send(MailCatalog::PASSWORD_RESET, $sourceEntityId, null, $email, [
            'resetLink' => $resetLink,
            'expiresInMinutes' => (string) $expiresInMinutes,
        ]);
    }

    public function sendAccountLocked(string $userId, string $email, string $reason, string $supportContact): ?array
    {
        return $this->send(MailCatalog::ACCOUNT_LOCKED, $userId, null, $email, [
            'reason' => $reason,
            'supportContact' => $supportContact,
        ]);
    }

    /**
     * PP.82: the password-set link for an account an admin created.
     * @param string $sourceEntityId the action-token id (unique per mail instance, see sendEmailVerification)
     */
    public function sendAccountSetup(string $sourceEntityId, string $email, string $setupLink, int $expiresInHours): ?array
    {
        return $this->send(MailCatalog::ACCOUNT_SETUP, $sourceEntityId, null, $email, [
            'setupLink' => $setupLink,
            'expiresInHours' => (string) $expiresInHours,
        ]);
    }

    // --- 4, 7-11, 13-14: standard queue, overridable per Wydarzenie ----------

    public function sendRegistrationCreated(string $registrationId, string $eventId, string $email, string $eventName, string $distanceName, string $amountDue): ?array
    {
        return $this->send(MailCatalog::REGISTRATION_CREATED, $registrationId, $eventId, $email, [
            'eventName' => $eventName,
            'distanceName' => $distanceName,
            'amountDue' => $amountDue,
        ]);
    }

    /**
     * Mail #5 ("Potwierdzenie płatności / bilet"). PP.19: when the Zapis has a start code
     * (`registrations.qr_code`, `pqr_...`), the mail carries it as an inline PNG QR (`cid:`) plus the
     * code as text; the block is appended after the rendered template, so event-level template
     * overrides keep it. A Zapis without a code sends no attachment.
     */
    public function sendPaymentConfirmed(
        string $registrationId,
        string $eventId,
        string $email,
        string $eventName,
        string $distanceName,
        string $myStartsLink,
        ?string $startCode = null,
    ): ?array {
        return $this->send(MailCatalog::PAYMENT_CONFIRMED, $registrationId, $eventId, $email, [
            'eventName' => $eventName,
            'distanceName' => $distanceName,
            'myStartsLink' => $myStartsLink,
        ], '', false, $startCode === null ? null : [
            'code' => $startCode,
            'contentId' => MailQrCode::START_CODE_CONTENT_ID,
            'filename' => MailQrCode::START_CODE_FILENAME,
            'label' => MailQrCode::START_CODE_LABEL,
        ]);
    }

    /**
     * Mail #6 ("Kod tożsamości"), sent once at Zawodnik creation to the
     * Opiekun's (guardian's) Konto — a child Zawodnik has no email of their
     * own (17 §1 row 6). PP.19: carries the Zawodnik's identity code (`zqr_...`) as an inline
     * PNG QR plus the code as text; without a code no attachment is sent.
     */
    public function sendIdentityCode(
        string $competitorId,
        string $guardianEmail,
        string $competitorName,
        ?string $identityCode = null,
    ): ?array {
        return $this->send(MailCatalog::IDENTITY_CODE, $competitorId, null, $guardianEmail, [
            'competitorName' => $competitorName,
        ], '', false, $identityCode === null ? null : [
            'code' => $identityCode,
            'contentId' => MailQrCode::IDENTITY_CODE_CONTENT_ID,
            'filename' => MailQrCode::IDENTITY_CODE_FILENAME,
            'label' => MailQrCode::IDENTITY_CODE_LABEL,
        ]);
    }

    /** PP.18: lets the scheduler skip (and not claim anything) when no mail could be enqueued anyway. */
    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * @param string $windowKey identifies the payment window the reminder belongs to (the registration's
     *                          `payment_expires_at`): one reminder per window, a reopened window gets its own
     */
    public function sendUnpaidReminder(string $registrationId, string $eventId, string $email, string $eventName, string $amountDue, string $payNowLink, string $expiresAt, string $windowKey): ?array
    {
        return $this->send(MailCatalog::UNPAID_REMINDER, $registrationId, $eventId, $email, [
            'eventName' => $eventName,
            'amountDue' => $amountDue,
            'payNowLink' => $payNowLink,
            'expiresAt' => $expiresAt,
        ], 'w' . substr(hash('sha256', $windowKey), 0, 12));
    }

    /**
     * Mail #8. One call per recipient — the caller (routes.php's event
     * update/cancel handlers) is responsible for iterating the event's
     * registered Zawodnicy/Konta and calling this once per recipient, exactly
     * like RegistrationRepository::bulkApproveRefundsForEvent() already
     * iterates per-registration for the refund side of a cancellation.
     */
    public function sendEventChanged(string $eventId, string $recipientEntityId, string $email, string $eventName, string $changeSummary, string $refundStatus): ?array
    {
        return $this->send(MailCatalog::EVENT_CHANGED, $eventId . ':' . $recipientEntityId, $eventId, $email, [
            'eventName' => $eventName,
            'changeSummary' => $changeSummary,
            'refundStatus' => $refundStatus,
        ]);
    }

    public function sendModerationDecision(string $eventId, string $email, string $eventName, string $decision, string $reason = ''): ?array
    {
        return $this->send(MailCatalog::MODERATION_DECISION, $eventId, $eventId, $email, [
            'eventName' => $eventName,
            'decision' => $decision,
            'reason' => $reason,
        ]);
    }

    /**
     * Mail #10 ("Publikacja wyników"). Triggered by official results publication: the publish
     * request only records a request row, and the scheduler's `mail.results_published` job fans out
     * to the recipients, writing DEFERRED outbox rows (no HTTP call) that `mail.outbox_retry` then
     * dispatches in rate-limited batches. $recipientEntityId is the 16-hex hash of the e-mail address.
     */
    public function sendResultsPublished(string $eventId, string $recipientEntityId, string $email, string $eventName, string $resultsLink): ?array
    {
        return $this->send(MailCatalog::RESULTS_PUBLISHED, $eventId . ':' . $recipientEntityId, $eventId, $email, [
            'eventName' => $eventName,
            'resultsLink' => $resultsLink,
        ], '', true);
    }

    public function sendVirtualResultVerdict(string $submissionId, string $eventId, string $email, string $eventName, string $decision, string $reason = ''): ?array
    {
        return $this->send(MailCatalog::VIRTUAL_RESULT_VERDICT, $submissionId, $eventId, $email, [
            'eventName' => $eventName,
            'decision' => $decision,
            'reason' => $reason,
        ]);
    }

    /** Mail #12: systemic (the shop is the platform's, not an organizer's — 17 §5), never has an $eventId. */
    public function sendShopOrderStatus(string $orderId, string $email, string $orderNumber, string $status, string $trackingNumber = ''): ?array
    {
        return $this->send(MailCatalog::SHOP_ORDER_STATUS, $orderId, null, $email, [
            'orderNumber' => $orderNumber,
            'status' => $status,
            'trackingNumber' => $trackingNumber,
        ]);
    }

    public function sendChallengeCompleted(string $participationId, string $email, string $challengeName, string $rewardLink): ?array
    {
        return $this->send(MailCatalog::CHALLENGE_COMPLETED, $participationId, null, $email, [
            'challengeName' => $challengeName,
            'rewardLink' => $rewardLink,
        ]);
    }

    public function sendRefundApproved(string $registrationId, string $eventId, string $email, string $eventName, string $amount, string $route): ?array
    {
        return $this->send(MailCatalog::REFUND_APPROVED, $registrationId, $eventId, $email, [
            'eventName' => $eventName,
            'amount' => $amount,
            'route' => $route,
        ]);
    }

    /**
     * @param array<string, string> $variables
     * @param bool $deferred write the outbox row only; mail.outbox_retry sends it
     * @param array{code: string, contentId: string, filename: string, label: string}|null $qr inline QR image
     * @return array<string, mixed>|null
     */
    private function send(string $mailType, string $sourceEntityId, ?string $eventId, string $recipientEmail, array $variables, string $keyDiscriminator = '', bool $deferred = false, ?array $qr = null): ?array
    {
        if (!$this->config->isConfigured() || $recipientEmail === '') {
            return null;
        }

        $template = $this->templates->resolve($mailType, $eventId);
        $subject = $this->renderer->render($template['subject'], $variables);
        $html = $this->renderer->render($template['bodyHtml'], $variables);
        $attachments = [];
        if ($qr !== null && trim($qr['code']) !== '') {
            $html .= MailQrCode::htmlBlock($qr['code'], $qr['contentId'], $qr['label']);
            $attachments[] = MailQrCode::attachment($qr['code'], $qr['contentId'], $qr['filename']);
        }

        // §6: deterministic, never a timestamp/random suffix — the template
        // version (bumped on every admin/organizer save, see
        // MailTemplateRepository::save()) is the only thing beyond the
        // (entity, mail type) pair that legitimately changes the key, and it
        // does so exactly when the rendered HTML itself legitimately changes.
        $idempotencyKey = sprintf('%s:%s:v%d', $sourceEntityId, $mailType, $template['version']);
        if ($keyDiscriminator !== '') {
            $idempotencyKey .= ':' . $keyDiscriminator;
        }

        $message = [
            'sourceEntityType' => $mailType,
            'sourceEntityId' => $sourceEntityId,
            'idempotencyKey' => $idempotencyKey,
            'priority' => MailCatalog::priorityFor($mailType),
            'to' => $recipientEmail,
            'subject' => $subject,
            'html' => $html,
            'contextId' => $eventId,
            'metadata' => ['mailType' => $mailType, 'templateVersion' => $template['version'], 'templateSource' => $template['source']],
            'attachments' => $attachments,
        ];

        return $deferred ? $this->outbox->enqueueDeferred($message) : $this->outbox->enqueue($message);
    }
}
