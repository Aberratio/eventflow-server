# Migrations

Plain SQL files applied in filename order by `ZmierzymyCzas\Database\Migrator`
(`php bin/migrate.php`). A `schema_migrations` ledger records each applied file with the
sha256 of its contents (line endings normalised).

## Numbering

Files are named `NNN_short_description.sql`. The numbers are not contiguous on purpose: gaps
are ranges reserved for a feature area, so parallel work on different areas does not fight
over the same number and a late addition can slot in next to related files. Use the next
free number in the range of the area you are changing, never reuse a number, and do not
renumber existing files.

## Never edit an applied migration

The Migrator re-applies any file whose checksum differs from the ledger. Once a migration has
been applied anywhere (staging, production, a colleague's database), treat it as immutable,
comments included. A change to the schema or data goes into a new migration.

## Idempotency

Every file must be safe to run more than once: running `migrate` twice must apply nothing the
second time, and a forced full re-apply must not change schema or data.

- Use `CREATE TABLE IF NOT EXISTS`, `DROP TABLE IF EXISTS` and `INSERT ... ON DUPLICATE KEY`
  / `WHERE NOT EXISTS` style data changes.
- Production and staging run MySQL, which rejects MariaDB-only syntax such as
  `ADD COLUMN IF NOT EXISTS`. Keep ALTER statements portable and guard them with a marker.

## `-- @guard:` markers

A line consisting only of

```sql
-- @guard: <kind> <table>.<object>
```

makes the Migrator run the **next SQL statement only** if the condition holds, evaluated
against `information_schema` by `MigrationGuard`. Supported kinds:

| Kind | Statement runs when |
|---|---|
| `column-missing` | the column does not exist (`ADD COLUMN`) |
| `column-exists` | the column exists (`DROP COLUMN`, `MODIFY`) |
| `index-missing` | the index does not exist |
| `index-exists` | the index exists |
| `foreign-key-missing` | the foreign key constraint does not exist |

```sql
-- @guard: column-missing users.event_limit
ALTER TABLE users ADD COLUMN event_limit INT NULL;
```

A malformed marker, an unknown kind, or a marker not directly followed by a SQL statement
makes the migration fail instead of being ignored as a comment.

## `-- @php:` steps

A line `-- @php: Class::method` runs a named backfill step implemented in PHP (see
`Migrator::parse()` for the allowed steps), for data changes that are impractical in SQL.
