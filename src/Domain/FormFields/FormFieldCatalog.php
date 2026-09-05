<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\FormFields;

/**
 * Zamknięty katalog 13 pozycji systemowych (model-formularza-zapisow.md §2).
 * Organizator nie tworzy nowych pozycji systemowych i nie zmienia ich
 * `storageLocation`/`controlClass` — jedyne, czym może sterować, to `state`
 * (w granicach dozwolonych przez klasę, patrz `controlClassFor()`), etykieta,
 * tooltip i widoczność na publicznej liście.
 */
final class FormFieldCatalog
{
    public const STORAGE_COMPETITOR = 'competitor';
    public const STORAGE_REGISTRATION = 'registration';

    public const CONTROL_LOCKED = 'locked'; // nieusuwalne: tylko `required`
    public const CONTROL_AGE_DRIVEN = 'age_driven'; // napędzające logikę: `required`, albo `hidden`/`shown` gdy odblokowane
    public const CONTROL_FREE = 'free'; // swobodne: `hidden` | `shown` | `required`

    public const STATE_HIDDEN = 'hidden';
    public const STATE_SHOWN = 'shown';
    public const STATE_REQUIRED = 'required';

    /** Anonimizacja jest jedynym systemowym polem bez stanu `required` (model §2: "Nie / Pokaż"). */
    public const ANONYMIZATION_STATES = [self::STATE_HIDDEN, self::STATE_SHOWN];

    /**
     * Kolejność = kolejność z modelu §2 (kolumna "Pozycja"), używana też jako
     * domyślny `sort_order` przy zasiewie (migracja 041).
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
     * Stany dozwolone dla danej pozycji systemowej *dziś* (model §3).
     *
     * Napędzające logikę (`birth_date`, `gender`) powinny odblokować `hidden`/
     * `shown` wyłącznie gdy Dystans wydarzenia nie klasyfikuje wiekowo i nie ma
     * widełek wieku ani składki NNW zależnej od wieku. Ten sygnał nie istnieje
     * jeszcze w kodzie: `event_distances.age_categories` jest zapisywaną, ale
     * nigdzie nieczytaną kolumną (grepped przed napisaniem tego kodu — brak
     * jakiegokolwiek odczytu w src/). Zamiast wymyślać nowy stan Dystansu w tym
     * tickecie, zostawiamy te dwie pozycje zawsze `required` — bezpieczniejszy
     * default (zbieramy więcej, nie mniej) niż zgadywanie. To udokumentowana
     * luka: gdy klasyfikacja wieku zostanie wpięta (osobny ticket), ta metoda
     * jest jedynym miejscem do zmiany — sprawdzić tu Dystanse wydarzenia i
     * zwrócić pełny zestaw stanów, gdy żaden nie klasyfikuje wiekowo.
     *
     * @return list<string>
     */
    public static function allowedStates(string $key): array
    {
        $controlClass = self::controlClassFor($key);
        return match ($controlClass) {
            self::CONTROL_LOCKED => [self::STATE_REQUIRED],
            self::CONTROL_AGE_DRIVEN => [self::STATE_REQUIRED], // patrz docblock powyżej — odblokowanie odłożone
            self::CONTROL_FREE => $key === 'anonymization'
                ? self::ANONYMIZATION_STATES
                : [self::STATE_HIDDEN, self::STATE_SHOWN, self::STATE_REQUIRED],
            default => [self::STATE_HIDDEN, self::STATE_SHOWN, self::STATE_REQUIRED],
        };
    }

    /** Zamknięta lista 16 województw (model §5). */
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
