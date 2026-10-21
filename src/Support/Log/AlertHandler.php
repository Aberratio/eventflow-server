<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerClientInterface;

/**
 * E-mails an alert (Central Mailer, priority `technical`) for records at ERROR or above
 * that carry `context['alert']`, throttled per alert key. Receives the already-redacted record.
 * Deliberately independent of the database and the mail outbox: an alert must still work when
 * the DB is what failed.
 */
final class AlertHandler extends AbstractProcessingHandler
{
    /** @param \Closure(): int|null $clock tests: fixed time */
    public function __construct(
        private readonly CentralMailerClientInterface $mailer,
        private readonly string $recipient,
        private readonly AlertThrottle $throttle,
        private readonly string $appEnv,
        private readonly string $logDir = '',
        private readonly ?\Closure $clock = null,
    ) {
        parent::__construct(Level::Error, true);
    }

    protected function write(LogRecord $record): void
    {
        $key = $record->context['alert'] ?? null;
        if (!is_string($key) || $key === '') {
            return;
        }

        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        $decision = $this->throttle->hit($key, $now);
        if (!$decision['send']) {
            return;
        }

        $window = max(1, $this->throttle->windowSeconds());
        $uid = is_string($record->extra['uid'] ?? null) ? $record->extra['uid'] : '';
        $context = json_encode($record->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $body = implode("\n", [
            'Time: ' . date(DATE_ATOM, $now),
            'Level: ' . $record->level->getName(),
            'Alert: ' . $key,
            'Uid: ' . $uid,
            'Message: ' . $record->message,
            'Context: ' . ($context === false ? '{}' : $context),
            '',
            'Suppressed since last alert: ' . $decision['suppressed'],
            'Full record: ' . ($this->logDir !== '' ? $this->logDir . '/app-' . date('Y-m-d', $now) . '.log' : 'PHP error_log') . ', uid ' . $uid,
        ]);

        try {
            $this->mailer->enqueue([
                'to' => $this->recipient,
                'subject' => sprintf('[ZC %s] ALERT %s', $this->appEnv, $key),
                'html' => '<pre>' . htmlspecialchars($body, ENT_QUOTES) . '</pre>',
                'text' => $body,
                'priority' => 'technical',
                'contextId' => 'zc-alert',
            ], sprintf('zc-alert:%s:%d', $key, intdiv($now, $window)));
        } catch (\Throwable $e) {
            // Never via the logger (no recursion) and never the message (may hold the HTTP body).
            error_log('[zmierzymy_czas] Alert e-mail failed for ' . $key . ': ' . get_class($e));
        }
    }
}
