<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CriticalMails;

use PDO;

/**
 * Reply-To for event-scoped mails (17 §4): the organizer's contact e-mail of the Wydarzenie
 * (`events.organizer_contact` JSON, `email` key), else the e-mail of the organizer's account
 * (`users.email` via `events.organizer_id`). Null when neither is a valid address - the mail
 * then goes out without replyTo and Central Mailer applies its global one.
 */
final class EventReplyToResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{email: string, name: ?string}|null */
    public function forEvent(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.organizer_contact AS contact, e.organizer_name AS organizerName, u.email AS accountEmail
             FROM events e LEFT JOIN users u ON u.id = e.organizer_id
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $contact = is_string($row['contact'] ?? null) ? json_decode($row['contact'], true) : null;
        $candidates = [
            is_array($contact) ? $contact['email'] ?? null : null,
            $row['accountEmail'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $email = is_string($candidate) ? trim($candidate) : '';
            if ($email !== '' && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $name = trim((string) ($row['organizerName'] ?? ''));

                return ['email' => $email, 'name' => $name !== '' && !preg_match('/[\x00-\x1F\x7F]/', $name) ? mb_substr($name, 0, 255) : null];
            }
        }

        return null;
    }
}
