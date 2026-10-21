<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/**
 * Is a new Zapis accepted for this Event right now?
 * Mirrors the client's EventRegistrationPage rules so the server, not the form, is the gate.
 *
 * Verdicts: `open` (a real Zapis), `test` (Draft in test mode - a dry run) or a
 * Polish reason the Zapis is refused. Staff of the Event (`$manager`) skip the time/status
 * rules (they add people after close) but never the publication rule.
 */
final class RegistrationOpenRules
{
    public const OPEN = 'open';
    public const TEST = 'test';

    /**
     * @param array<string, mixed> $event the EventRepository::find() row
     * @return array{verdict: string, message: ?string} message is null unless refused
     */
    public static function evaluate(array $event, \DateTimeImmutable $now, bool $manager): array
    {
        $publication = (string) ($event['publicationStatus'] ?? '');
        if ($publication === 'draft' && !empty($event['isTest'])) {
            return ['verdict' => self::TEST, 'message' => null];
        }
        if (!empty($event['isExternal'])) {
            return self::refuse('Zapisy na to wydarzenie prowadzone są na zewnętrznej stronie organizatora.');
        }
        if ($publication !== 'published') {
            return self::refuse('Zapisy na to wydarzenie są zamknięte.');
        }
        if ($manager) {
            return ['verdict' => self::OPEN, 'message' => null];
        }

        $registrationStatus = (string) ($event['registrationStatus'] ?? '');
        if ($registrationStatus === 'closed') {
            return self::refuse('Zapisy na to wydarzenie są zamknięte.');
        }
        if ($registrationStatus === 'none') {
            return self::refuse('Zapisy na to wydarzenie jeszcze się nie rozpoczęły.');
        }

        $opens = self::moment((string) ($event['registrationOpenDate'] ?? ''), false);
        if ($opens !== null && $opens > $now) {
            return self::refuse('Zapisy na to wydarzenie jeszcze się nie rozpoczęły.');
        }

        $eventDate = self::moment((string) ($event['date'] ?? ''), true);
        $deadline = !empty($event['registrationUntilEventStart'])
            ? $eventDate
            : self::moment((string) ($event['registrationDeadline'] ?? ''), true);
        if (($deadline !== null && $deadline < $now) || ($eventDate !== null && $eventDate < $now)) {
            return self::refuse('Termin zapisów na to wydarzenie minął.');
        }

        return ['verdict' => self::OPEN, 'message' => null];
    }

    /** @return array{verdict: string, message: string} */
    private static function refuse(string $message): array
    {
        return ['verdict' => 'closed', 'message' => $message];
    }

    /** A date-only value means the whole day: its start for an opening, its end for a deadline. */
    private static function moment(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $moment = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $moment->setTime(23, 59, 59);
        }

        return $moment;
    }
}
