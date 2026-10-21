<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Registrations;

use ZmierzymyCzas\Support\InputValidationException;

/**
 * The Kategoria wiekowa of a Zapis is derived on the server from the Zawodnik's birth year
 * and gender and from the Dystans's configuration; the client never chooses it
 * (slownik-domenowy.md, "Kategoria wiekowa": wynika z płci i daty urodzenia Zawodnika).
 *
 * Pure: no database, no clock. Age categories are birth-YEAR brackets ("płeć + rocznik") with
 * absolute `birthYearFrom` / `birthYearTo`, so no reference date takes part: birthdays inside the
 * year do not matter and neither does whether the race date or the registration date is used.
 * (The "age on the start day" rule is for age limits, which are not implemented, and for the NNW
 * premium, which RegistrationRepository computes against the event date.)
 *
 * Three configurations of a Dystans:
 *  1. non-empty `age_categories`: computed. The first configured category that fits wins (the same
 *     order the client's own preview uses). Special categories ("Kategoria specjalna", chosen by
 *     the participant) are kept only when they are configured on the Dystans.
 *  2. no age categories but a legacy plain `categories` list: organizer-defined labels the
 *     participant picks, so the client value must be exactly one of them (allowlist).
 *  3. nothing configured: the category is stored empty and the client value is ignored.
 */
final class AgeCategoryResolver
{
    /** registrations.category is VARCHAR(80) NOT NULL. */
    public const MAX_LENGTH = 80;

    /**
     * @param ?int $birthYear the Zawodnik's birth year, null when unknown
     * @param ?string $gender 'M', 'K' or null when unknown
     * @param mixed $ageCategories list (or JSON text) of {id, name, gender, birthYearFrom, birthYearTo}
     * @param mixed $legacyCategories list (or JSON text) of plain strings
     * @param mixed $specialCategories list (or JSON text) of objects with a `name`
     * @throws InputValidationException with field `category`
     */
    public static function resolve(
        string $clientCategory,
        ?int $birthYear,
        ?string $gender,
        mixed $ageCategories,
        mixed $legacyCategories,
        mixed $specialCategories
    ): string {
        $age = self::ageCategories($ageCategories);
        $legacy = self::strings($legacyCategories);

        if ($age !== []) {
            if ($birthYear === null) {
                throw new InputValidationException(
                    'Ten dystans ma kategorie wiekowe. Uzupełnij datę urodzenia zawodnika (w profilu albo w danych podopiecznego), aby przypisać kategorię.',
                    'category',
                    'registration_birth_date_required'
                );
            }

            $agePart = self::matchAgeCategory($age, $birthYear, $gender);
            if ($agePart === null) {
                throw new InputValidationException(
                    'Dane zawodnika (rok urodzenia i płeć) nie pasują do żadnej kategorii wiekowej tego dystansu.',
                    'category',
                    'registration_category_unavailable'
                );
            }

            $parts = [$agePart, ...self::specials($clientCategory, $age, $legacy, $specialCategories)];

            return self::fit(implode(', ', $parts));
        }

        if ($legacy !== []) {
            $chosen = trim(explode(', ', trim($clientCategory))[0]);
            if (!in_array($chosen, $legacy, true)) {
                throw self::invalid('Wybrana kategoria nie jest dostępna na tym dystansie.');
            }

            return self::fit($chosen);
        }

        return '';
    }

    /**
     * @param list<array{name: string, gender: string, from: ?int, to: ?int}> $age
     */
    private static function matchAgeCategory(array $age, int $birthYear, ?string $gender): ?string
    {
        foreach ($age as $category) {
            if ($category['from'] !== null && $birthYear < $category['from']) {
                continue;
            }
            if ($category['to'] !== null && $birthYear > $category['to']) {
                continue;
            }
            if ($category['gender'] !== '' && $category['gender'] !== $gender) {
                continue;
            }

            return $category['name'];
        }

        return null;
    }

    /**
     * Special categories the client asked for: only configured ones, de-duplicated, in the client's
     * order. The client's own copy of the age part (the first token, or a legacy label) is dropped,
     * never trusted; every later token must be a configured special category or the request is rejected.
     *
     * @param list<array{name: string, gender: string, from: ?int, to: ?int}> $age
     * @param list<string> $legacy
     * @return list<string>
     */
    private static function specials(string $clientCategory, array $age, array $legacy, mixed $specialCategories): array
    {
        $configured = [];
        foreach (self::decodeList($specialCategories) as $special) {
            if (is_array($special) && trim((string) ($special['name'] ?? '')) !== '') {
                $configured[] = trim((string) $special['name']);
            }
        }
        $ignored = [...array_column($age, 'name'), ...$legacy];

        $result = [];
        foreach (explode(',', $clientCategory) as $position => $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, $ignored, true)) {
                continue;
            }
            if ($position === 0 && !in_array($token, $configured, true)) {
                // The first token is the client's own guess of the age part ("K50+, Weteran"): the
                // server computed that part itself, so a wrong guess is ignored, not an error.
                continue;
            }
            if (!in_array($token, $configured, true)) {
                throw self::invalid('Wybrana kategoria specjalna nie jest dostępna na tym dystansie.');
            }
            if (!in_array($token, $result, true)) {
                $result[] = $token;
            }
        }

        return $result;
    }

    private static function fit(string $category): string
    {
        if (mb_strlen($category) > self::MAX_LENGTH) {
            throw self::invalid('Wybrana kategoria jest zbyt długa (maksymalnie ' . self::MAX_LENGTH . ' znaków).');
        }

        return $category;
    }

    private static function invalid(string $message): InputValidationException
    {
        return new InputValidationException($message, 'category', 'registration_category_invalid');
    }

    /** @return list<array{name: string, gender: string, from: ?int, to: ?int}> */
    private static function ageCategories(mixed $raw): array
    {
        $result = [];
        foreach (self::decodeList($raw) as $category) {
            if (!is_array($category)) {
                continue;
            }
            $name = trim((string) ($category['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $result[] = [
                'name' => $name,
                'gender' => trim((string) ($category['gender'] ?? '')),
                'from' => self::year($category['birthYearFrom'] ?? null),
                'to' => self::year($category['birthYearTo'] ?? null),
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private static function strings(mixed $raw): array
    {
        $result = [];
        foreach (self::decodeList($raw) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return $result;
    }

    private static function year(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) || is_float($value) ? (int) $value : null;
    }

    /** @return list<mixed> */
    private static function decodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : json_decode($raw, true);
        }

        return is_array($raw) ? array_values($raw) : [];
    }
}
