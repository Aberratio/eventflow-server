# Zmierzymy Czas Server

PHP 8.2 REST API for the React/Vite frontend. The API is intentionally shaped to match the frontend `ApiAdapter` contract.

## Stack

- Slim 4
- PDO + MySQL
- JWT bearer auth
- PHPUnit

## Local Setup

```sh
composer install
copy .env.example .env
composer migrate
composer seed
composer serve
```

The API runs at `http://localhost:8081/api/v1`.

`composer serve` is a foreground development server. Keep that terminal open while
using the frontend. If the terminal is closed or the process is stopped, browser
requests to the API will fail with `connection refused`.

The `serve` script disables Composer's default 300-second process timeout. If you
start the server manually instead, run `php -S localhost:8081 -t public` from this
directory.

`composer migrate` updates the existing MySQL schema. Run it after pulling backend
changes, especially when the API starts returning 500 errors after startup.

`composer seed` rebuilds the demo database from scratch: it drops existing tables,
runs migrations, and loads demo data. Use it only when local data can be reset.

Quick sanity checks after starting the server:

```sh
curl http://localhost:8081/api/v1/health
curl http://localhost:8081/api/v1/events
```

If `/health` works but `/events` returns 500 with an SQL error such as
`Unknown column ...`, the database schema is stale. Run:

```sh
composer migrate
```

If the database is missing demo data or the schema is badly out of sync, reset it
with:

```sh
composer seed
```

Demo users seeded by `composer seed` all use password `demo1234`:

- `jan@example.com` participant
- `marek@timing.pl` organizer
- `monika@zmierzymyczas.pl` moderator
- `admin@zmierzymyczas.pl` admin

## Frontend

Set the client env variable:

```sh
VITE_API_URL=http://localhost:8081/api/v1
```

When `VITE_API_URL` is set, the React app uses the real HTTP adapter. To use mock
data instead, leave `VITE_API_URL` empty in the client env file.
