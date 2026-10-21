<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

/**
 * Closed catalog of the 13 system items.
 * An organizer cannot create new system items or change their
 * `storageLocation`/`controlClass` — the only things they control are `state`
 * (within the limits allowed by the class, see `controlClassFor()`), the label,
 * the tooltip and visibility on the public list.
 */
final class FormFieldCatalog
{
    public const STORAGE_COMPETITOR = 'competitor';
    public const STORAGE_REGISTRATION = 'registration';

    public const CONTROL_LOCKED = 'locked'; // non-removable: only `required`
    public const CONTROL_AGE_DRIVEN = 'age_driven'; // logic-driving: `required`, or `hidden`/`shown` when unlocked
    public const CONTROL_FREE = 'free'; // free: `hidden` | `shown` | `required`

    public const STATE_HIDDEN = 'hidden';
    public const STATE_SHOWN = 'shown';
    public const STATE_REQUIRED = 'required';

    /** Anonymization is the only system field without a `required` state ("No / Show"). */
    public const ANONYMIZATION_STATES = [self::STATE_HIDDEN, self::STATE_SHOWN];

    /**
     * Order = the order of the catalog (column "Pozycja"), also used as the
     * default `sort_order` when seeding (migration 041).
     *
     * @return array<int, array{key: string, label: string, storageLocation: string, controlClass: string}>
     */
    public static function systemPositions(): array
    {
        return [
            ['key' => 'first_name', 'label' => 'Imię', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_LOCKED],
            ['key' => 'last_name', 'label' => 'Nazwisko', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_LOCKED],
            ['key' => 'contact_email', 'label' => 'E-mail kontaktowy', 'storageLocation' => self::STORAGE_REGISTRATION, 'controlClass' => self::CONTROL_LOCKED],
            ['key' => 'birth_date', 'label' => 'Data urodzenia', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_AGE_DRIVEN],
            ['key' => 'gender', 'label' => 'Płeć', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_AGE_DRIVEN],
            ['key' => 'anonymization', 'label' => 'Anonimizacja', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_FREE],
            ['key' => 'country', 'label' => 'Kraj', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_FREE],
            ['key' => 'city', 'label' => 'Miejscowość', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_FREE],
            ['key' => 'postal_code', 'label' => 'Kod pocztowy', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_FREE],
            ['key' => 'voivodeship', 'label' => 'Województwo', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_FREE],
            ['key' => 'address', 'label' => 'Adres', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_FREE],
            ['key' => 'phone', 'label' => 'Telefon', 'storageLocation' => self::STORAGE_COMPETITOR, 'controlClass' => self::CONTROL_FREE],
            ['key' => 'club', 'label' => 'Klub', 'storageLocation' => self::STORAGE_REGISTRATION, 'controlClass' => self::CONTROL_FREE],
        ];
    }

    /** @return list<string> */
    public static function systemKeys(): array
    {
        return array_map(static fn (array $p): string => $p['key'], self::systemPositions());
    }

    public static function isSystemKey(string $key): bool
    {
        return in_array($key, self::systemKeys(), true);
    }

    public static function find(string $key): ?array
    {
        foreach (self::systemPositions() as $position) {
            if ($position['key'] === $key) {
                return $position;
            }
        }
        return null;
    }

    public static function controlClassFor(string $key): ?string
    {
        return self::find($key)['controlClass'] ?? null;
    }

    /**
     * States allowed for a given system item *today*.
     *
     * Logic-driving items (`birth_date`, `gender`) should unlock `hidden`/
     * `shown` only when the event's Dystans does not classify by age and has no
     * age brackets or age-dependent NNW premium. That signal does not exist yet
     * in the code: `event_distances.age_categories` is a written but never-read
     * column (grepped before writing this code — no read anywhere in
     * src/). Rather than inventing a new Dystans state here,
     * we leave these two items always `required` — a safer
     * default (we collect more, not less) than guessing. This is a documented
     * gap: once age classification is wired in, this method
     * is the only place to change — check the event's Dystanse here and
     * return the full set of states when none of them classifies by age.
     *
     * @return list<string>
     */
    public static function allowedStates(string $key): array
    {
        $controlClass = self::controlClassFor($key);
        return match ($controlClass) {
            self::CONTROL_LOCKED => [self::STATE_REQUIRED],
            self::CONTROL_AGE_DRIVEN => [self::STATE_REQUIRED], // see the docblock above — unlocking deferred
            self::CONTROL_FREE => $key === 'anonymization'
                ? self::ANONYMIZATION_STATES
                : [self::STATE_HIDDEN, self::STATE_SHOWN, self::STATE_REQUIRED],
            default => [self::STATE_HIDDEN, self::STATE_SHOWN, self::STATE_REQUIRED],
        };
    }

    /** Closed list of the 16 voivodeships. */
    public static function voivodeships(): array
    {
        return [
            'dolnośląskie', 'kujawsko-pomorskie', 'lubelskie', 'lubuskie',
            'łódzkie', 'małopolskie', 'mazowieckie', 'opolskie',
            'podkarpackie', 'podlaskie', 'pomorskie', 'śląskie',
            'świętokrzyskie', 'warmińsko-mazurskie', 'wielkopolskie', 'zachodniopomorskie',
        ];
    }
}
