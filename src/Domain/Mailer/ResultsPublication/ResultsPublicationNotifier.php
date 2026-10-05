<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\ResultsPublication;

use PDO;
use Psr\Log\LoggerInterface;
use ZmierzymyCzas\Domain\Mailer\CentralMailer\CentralMailerOutboxRepository;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\CriticalMailer;
use ZmierzymyCzas\Domain\Mailer\CriticalMails\MailCatalog;

/**
 * PP.19: fan-out of mail #10 ("Publikacja wyników"). For every pending publication request it writes one
 * DEFERRED outbox row per distinct confirmed e-mail address of the event and distance (no HTTP call;
 * mail.outbox_retry paces the sending). One mail per address per event: the dedup key is the e-mail
 * hash and does not depend on the template version, the distance or how often results are re-published.
 */
final class ResultsPublicationNotifier
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ResultsPublicationMailRequests $requests,
        private readonly CriticalMailer $mailer,
        private readonly CentralMailerOutboxRepository $outboxRows,
        private readonly string $appUrl,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array{requests: int, queued: int, skippedDuplicate: int} */
    public function run(\DateTimeImmutable $now, int $requestLimit = 20): array
    {
        $pending = $this->requests->listPending($requestLimit);
        $counters = ['requests' => count($pending), 'queued' => 0, 'skippedDuplicate' => 0];

        foreach ($pending as $request) {
            $result = $this->fanOut($request['eventId'], $request['distanceId']);
            $this->requests->markProcessed($request['eventId'], $request['distanceId'], $request['requestedAt'], $result['queued'], $now);
            $counters['queued'] += $result['queued'];
            $counters['skippedDuplicate'] += $result['skippedDuplicate'];
        }

        return $counters;
    }

    /** @return array{queued: int, skippedDuplicate: int} */
    private function fanOut(string $eventId, string $distanceId): array
    {
        $result = ['queued' => 0, 'skippedDuplicate' => 0];
        $event = $this->pdo->prepare('SELECT name, slug FROM events WHERE id = :id');
        $event->execute(['id' => $eventId]);
        $eventRow = $event->fetch();
        if ($eventRow === false) {
            return $result;
        }
        $resultsLink = $this->appUrl . '/events/' . rawurlencode((string) $eventRow['slug']) . '/results';

        $stmt = $this->pdo->prepare(
            "SELECT user_email AS email FROM registrations
             WHERE event_id = :eventId AND distance_id = :distanceId AND status = 'confirmed'
               AND user_email IS NOT NULL AND user_email <> ''
             ORDER BY registered_at ASC, id ASC"
        );
        $stmt->execute(['eventId' => $eventId, 'distanceId' => $distanceId]);

        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            $address = trim((string) $row['email']);
            $normalized = mb_strtolower($address);
            if ($address === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            $recipientKey = substr(hash('sha256', $normalized), 0, 16);
            if ($this->outboxRows->existsForSource(MailCatalog::RESULTS_PUBLISHED, $eventId . ':' . $recipientKey)) {
                $result['skippedDuplicate']++;
                continue;
            }

            try {
                if ($this->mailer->sendResultsPublished($eventId, $recipientKey, $address, (string) $eventRow['name'], $resultsLink) !== null) {
                    $result['queued']++;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Results-published mail could not be queued', [
                    'eventId' => $eventId,
                    'distanceId' => $distanceId,
                    'errorClass' => $e::class,
                ]);
            }
        }

        return $result;
    }
}
