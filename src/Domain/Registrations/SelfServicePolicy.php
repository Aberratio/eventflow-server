<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

/**
 * R-02 (spec 20 "Answer" §2-3, zakres P2 - do potwierdzenia przez Zamawiającego): may the owner of a
 * Zapis change it on their own right now? One policy, one date per Wydarzenie, shared by every
 * self-service operation (cancellation today; change of Dystans and transfer reuse it as they land).
 *
 * Rules for the owner: the organizer has not switched self-service off for the Wydarzenie
 * (`selfServiceEnabled`), the Wydarzenie is neither cancelled nor finished nor already started, `now`
 * is before the cut-off date (`selfServiceDeadline`; unset = DEFAULT_LEAD_DAYS before the start), and the
 * Zapis is still live (`pending` or `confirmed`). The Event's manager is never restricted here - that
 * is the behaviour the organizer's own cancel route always had.
 *
 * Pure: no I/O, the clock is injected so boundaries are testable.
 */
final class SelfServicePolicy
{
    /** Default cut-off when the organizer set none: this many days before the start of the Wydarzenie. */
    public const DEFAULT_LEAD_DAYS = 7;

    public const REASON_DISABLED = 'self_service_disabled';
    public const REASON_CLOSED = 'self_service_closed';
    public const REASON_EVENT_OVER = 'event_over';
    public const REASON_REGISTRATION_INACTIVE = 'registration_not_active';

    /** Zapis statuses the owner may still act on. */
    private const ACTIVE_STATUSES = ['pending', 'confirmed'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock defaults to the current time */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $event the EventRepository::find() row
     * @param array<string, mixed> $registration the RegistrationRepository::find() row
     * @return array{allowed: bool, reason: ?string, deadline: ?string} `deadline` = the effective cut-off (ATOM) or null
     */
    public function evaluate(array $event, array $registration, bool $isManager): array
    {
        $deadline = self::effectiveDeadline($event);
        $deadlineText = $deadline?->format(DATE_ATOM);
        if ($isManager) {
            return ['allowed' => true, 'reason' => null, 'deadline' => $deadlineText];
        }
        $deny = static fn (string $reason): array => ['allowed' => false, 'reason' => $reason, 'deadline' => $deadlineText];

        if (array_key_exists('selfServiceEnabled', $event) && !$event['selfServiceEnabled']) {
            return $deny(self::REASON_DISABLED);
        }
        $now = ($this->clock)();
        $start = self::eventStart($event);
        if (in_array((string) ($event['publicationStatus'] ?? ''), ['cancelled', 'finished'], true) || ($start !== null && $start <= $now)) {
            return $deny(self::REASON_EVENT_OVER);
        }
        if (!in_array((string) ($registration['status'] ?? ''), self::ACTIVE_STATUSES, true)) {
            return $deny(self::REASON_REGISTRATION_INACTIVE);
        }
        if ($deadline !== null && $now >= $deadline) {
            return $deny(self::REASON_CLOSED);
        }

        return ['allowed' => true, 'reason' => null, 'deadline' => $deadlineText];
    }

    /**
     * The cut-off that applies: the organizer's own date, else DEFAULT_LEAD_DAYS before the start.
     * Null only when neither exists (a Wydarzenie with no parsable date).
     *
     * @param array<string, mixed> $event
     */
    public static function effectiveDeadline(array $event): ?\DateTimeImmutable
    {
        $own = self::parse($event['selfServiceDeadline'] ?? null);
        if ($own !== null) {
            return $own;
        }
        $start = self::eventStart($event);

        return $start?->modify('-' . self::DEFAULT_LEAD_DAYS . ' days');
    }

    /**
     * Organizer-supplied cut-off check: unset is fine; set it must parse and fall before the start.
     *
     * @param array<string, mixed> $event the Wydarzenie as it will be after the edit (date included)
     */
    public static function deadlineIsValid(mixed $value, array $event): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        $deadline = self::parse($value);
        if ($deadline === null) {
            return false;
        }
        $start = self::eventStart($event);

        return $start === null || $deadline < $start;
    }

    /** Normalises an organizer-supplied cut-off for the DATETIME column; null when blank or unparsable. */
    public static function normalizeDeadline(mixed $value): ?string
    {
        return self::parse($value)?->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $event */
    private static function eventStart(array $event): ?\DateTimeImmutable
    {
        return self::parse($event['date'] ?? null);
    }

    private static function parse(mixed $value): ?\DateTimeImmutable
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        try {
            // Instants with an offset ("...Z") are read in the server's zone, like the stored DATETIME.
            return (new \DateTimeImmutable($text))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
