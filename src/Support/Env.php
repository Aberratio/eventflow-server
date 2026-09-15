<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    /**
     * Keys displaced by {@see set()}, mapped to what they held before (null = absent).
     *
     * @var array<string, string|null>
     */
    private static array $overrides = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            // An explicit override outlives a reload. Tests set theirs before the
            // code under test boots, and booting is often what re-reads `.env`
            // (HttpTestCase::setUp does exactly that before building the app), so
            // without this the file would silently win the race every time.
            if (array_key_exists($key, self::$overrides)) {
                continue;
            }

            self::$values[$key] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Overrides one key for the lifetime of the process, remembering what was there.
     *
     * Exists because `get()` above answers from `self::$values` first, so a value
     * loaded from `.env` beats anything the process environment says. That is fine
     * for the application — `.env` is the configuration — but it makes `putenv()`
     * useless to a test: the moment any test in the run has called `load()`, a key
     * present in `.env` can no longer be steered from the outside, and the test
     * silently exercises the developer's own configuration instead of its fixture.
     * Whether it passed then came down to suite order, which is how the BZ contract
     * tests could fail with 401 while the unit tests covering the same config passed.
     *
     * Deliberately not fixed by making the process environment win in `get()`: that
     * would change which configuration a deployment actually runs with, which is a
     * far bigger decision than a test seam.
     */
    public static function set(string $key, string $value): void
    {
        if (!array_key_exists($key, self::$overrides)) {
            self::$overrides[$key] = self::$values[$key] ?? null;
        }

        self::$values[$key] = $value;
    }

    /**
     * Puts back whatever `set()` displaced — the previous `.env` value, or absence.
     *
     * Absence matters as much as a value: a test that simply dropped the key would
     * strip the developer's `.env` entry for every test that runs after it.
     */
    public static function restore(string $key): void
    {
        if (!array_key_exists($key, self::$overrides)) {
            return;
        }

        $original = self::$overrides[$key];
        unset(self::$overrides[$key]);

        if ($original === null) {
            unset(self::$values[$key]);

            return;
        }

        self::$values[$key] = $original;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'true' : 'false');
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
