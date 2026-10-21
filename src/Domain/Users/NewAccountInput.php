<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Users;

use ZmierzymyCzas\Domain\Auth\PasswordRule;
use ZmierzymyCzas\Support\InputValidationException;

/**
 * Input rules for the two ways a Konto is created over HTTP (POST /users by an admin,
 * POST /auth/register by an anonymous caller). Pure (no database) so the rules are unit-testable;
 * the e-mail uniqueness check stays in the route. Every violation throws InputValidationException,
 * which JsonErrorMiddleware renders as 422 with `details.field`.
 *
 * The trusted internal callers of UserRepository::create() (seed route) do not go through here.
 */
final class NewAccountInput
{
    private const NAME_MAX = 180;
    private const EMAIL_MAX = 255;
    private const MESSAGE_REQUIRED = 'Pole „%s” jest wymagane.';
    private const MESSAGE_INVALID = 'Pole „%s” ma nieprawidłową wartość.';
    private const MESSAGE_TOO_LONG = 'Pole „%s” może mieć najwyżej %d znaków.';

    /** Optional text columns: key => [label, max length in characters]. */
    private const TEXT_FIELDS = [
        'phone' => ['Telefon', 64],
        'club' => ['Klub', 180],
        'companyName' => ['Nazwa firmy', 255],
        'nip' => ['NIP', 32],
        'description' => ['Opis', 65535],
    ];

    public static function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the payload with the e-mail trimmed and lower-cased
     * @throws InputValidationException
     */
    public static function forAdminCreate(array $payload): array
    {
        $payload['name'] = self::name($payload);
        $payload['email'] = self::email($payload);

        if (
            array_key_exists('role', $payload) && $payload['role'] !== null
            && (!is_string($payload['role']) || !in_array($payload['role'], UserRepository::ROLES, true))
        ) {
            throw new InputValidationException('Wybierz poprawną rolę.', 'role', 'invalid_role');
        }
        if (
            array_key_exists('status', $payload) && $payload['status'] !== null
            && (!is_string($payload['status']) || !in_array($payload['status'], UserRepository::STATUSES, true))
        ) {
            throw new InputValidationException('Wybierz poprawny status konta.', 'status', 'invalid_status');
        }
        if (isset($payload['gender']) && (!is_string($payload['gender']) || !in_array($payload['gender'], ['M', 'K'], true))) {
            throw self::invalid('gender', 'Płeć');
        }
        if (isset($payload['birthYear'])) {
            $year = $payload['birthYear'];
            if (!is_int($year) && !(is_string($year) && preg_match('/^\d{1,4}$/', $year) === 1)) {
                throw self::invalid('birthYear', 'Rok urodzenia');
            }
            if ((int) $year < 1900 || (int) $year > (int) date('Y')) {
                throw self::invalid('birthYear', 'Rok urodzenia');
            }
            $payload['birthYear'] = (int) $year;
        }
        if (isset($payload['eventLimit'])) {
            $limit = $payload['eventLimit'];
            if (!is_int($limit) && !(is_string($limit) && preg_match('/^\d{1,9}$/', $limit) === 1)) {
                throw self::invalid('eventLimit', 'Limit wydarzeń');
            }
            if ((int) $limit < 0 || (int) $limit > 1000000) {
                throw self::invalid('eventLimit', 'Limit wydarzeń');
            }
            $payload['eventLimit'] = (int) $limit;
        }
        if (isset($payload['birthDate'])) {
            $date = $payload['birthDate'];
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw self::invalid('birthDate', 'Data urodzenia');
            }
        }
        foreach (self::TEXT_FIELDS as $key => [$label, $max]) {
            if (!isset($payload[$key])) {
                continue;
            }
            if (!is_string($payload[$key])) {
                throw self::invalid($key, $label);
            }
            if (mb_strlen($payload[$key]) > $max) {
                throw new InputValidationException(sprintf(self::MESSAGE_TOO_LONG, $label, $max), $key);
            }
        }
        foreach (['address' => 'Adres', 'sosContacts' => 'Kontakty SOS'] as $key => $label) {
            if (isset($payload[$key]) && !is_array($payload[$key])) {
                throw self::invalid($key, $label);
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name: string, email: string, password: string}
     * @throws InputValidationException
     */
    public static function forRegistration(array $payload): array
    {
        $name = self::name($payload);
        $email = self::email($payload);
        $password = $payload['password'] ?? null;
        if ($password === null || $password === '') {
            throw new InputValidationException(sprintf(self::MESSAGE_REQUIRED, 'Hasło'), 'password');
        }
        if (!is_string($password)) {
            throw self::invalid('password', 'Hasło');
        }
        $violation = PasswordRule::violation($password, null);
        if ($violation !== null) {
            throw new InputValidationException($violation['message'], 'password', $violation['code']);
        }

        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    /** @param array<string, mixed> $payload */
    private static function name(array $payload): string
    {
        $value = $payload['name'] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new InputValidationException(sprintf(self::MESSAGE_REQUIRED, 'Imię i nazwisko'), 'name');
        }
        if (!is_string($value)) {
            throw self::invalid('name', 'Imię i nazwisko');
        }
        $value = trim($value);
        if (mb_strlen($value) > self::NAME_MAX) {
            throw new InputValidationException(sprintf(self::MESSAGE_TOO_LONG, 'Imię i nazwisko', self::NAME_MAX), 'name');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function email(array $payload): string
    {
        $value = $payload['email'] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new InputValidationException(sprintf(self::MESSAGE_REQUIRED, 'Adres e-mail'), 'email');
        }
        if (!is_string($value)) {
            throw new InputValidationException('Podaj poprawny adres e-mail.', 'email', 'invalid_email');
        }
        $email = self::normalizeEmail($value);
        if (strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InputValidationException('Podaj poprawny adres e-mail.', 'email', 'invalid_email');
        }

        return $email;
    }

    private static function invalid(string $field, string $label): InputValidationException
    {
        return new InputValidationException(sprintf(self::MESSAGE_INVALID, $label), $field);
    }
}
