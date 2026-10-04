#!/usr/bin/env bash
# Weekly restore test, run by hosting cron: restores the newest daily dump into
# a dedicated scratch database, compares it with the manifest, then wipes it.
# Usage: bash db-restore-check.sh --backup-dir PATH --restore-env-file PATH [--max-age-hours 26]
#
# The restore env file uses DISTINCT keys so the production .env cannot be passed
# by mistake: RESTORE_DB_HOST RESTORE_DB_PORT RESTORE_DB_DATABASE
# RESTORE_DB_USERNAME RESTORE_DB_PASSWORD. The scratch database name must contain
# 'restore'.
# Exit codes: 70 scratch name unsafe, 71 target equals source, 72 dump invalid,
# 73 newest dump too old, 74 dump would switch database, 75 restore failed,
# 76 table count mismatch, 77 schema_migrations mismatch, 78 no dump found,
# 79 checksum mismatch, 80 bad usage/config. Secrets are never printed.
set -Eeuo pipefail
umask 077

BACKUP_DIR=""
RESTORE_ENV=""
MAX_AGE_HOURS=26

fail() {
  local code="$1"
  shift
  echo "$*" >&2
  exit "$code"
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --backup-dir) BACKUP_DIR="${2:-}"; shift 2 ;;
    --restore-env-file) RESTORE_ENV="${2:-}"; shift 2 ;;
    --max-age-hours) MAX_AGE_HOURS="${2:-}"; shift 2 ;;
    *) fail 80 "Unknown argument: $1" ;;
  esac
done

[ -n "$BACKUP_DIR" ] || fail 80 "--backup-dir is required."
[ -n "$RESTORE_ENV" ] || fail 80 "--restore-env-file is required."
[ -d "$BACKUP_DIR" ] || fail 80 "Backup directory not found: $BACKUP_DIR"
[ -f "$RESTORE_ENV" ] || fail 80 "Restore env file not found: $RESTORE_ENV"
case "$MAX_AGE_HOURS" in '' | *[!0-9]* | 0) fail 80 "--max-age-hours must be a positive number." ;; esac

for tool in mysql gzip sha256sum find; do
  command -v "$tool" >/dev/null 2>&1 || fail 80 "Required command not found: $tool"
done

get_env() {
  local key="$1" line
  line="$(grep -E "^${key}=" "$RESTORE_ENV" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line%$'\r'}"
  line="${line%\"}"
  line="${line#\"}"
  line="${line%\'}"
  line="${line#\'}"
  printf '%s' "$line"
}

R_HOST="$(get_env RESTORE_DB_HOST)"
R_PORT="$(get_env RESTORE_DB_PORT)"
R_DB="$(get_env RESTORE_DB_DATABASE)"
R_USER="$(get_env RESTORE_DB_USERNAME)"
R_PASS="$(get_env RESTORE_DB_PASSWORD)"

if [ -z "$R_HOST" ] || [ -z "$R_PORT" ] || [ -z "$R_DB" ] || [ -z "$R_USER" ]; then
  fail 80 "RESTORE_DB_* settings are incomplete in $RESTORE_ENV"
fi

R_DB_LOWER="$(printf '%s' "$R_DB" | tr '[:upper:]' '[:lower:]')"
case "$R_DB_LOWER" in
  *restore*) ;;
  *) fail 70 "Refusing to restore into a database whose name does not contain 'restore'." ;;
esac
case "$R_DB" in
  *[!A-Za-z0-9_]*) fail 70 "Refusing to restore into a database whose name has unsupported characters." ;;
esac

DUMP=""
while IFS= read -r f; do
  DUMP="$f"
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'daily-*.sql.gz' | sort)
[ -n "$DUMP" ] || fail 78 "No daily-*.sql.gz found in $BACKUP_DIR"
NAME="$(basename "$DUMP" .sql.gz)"

if ! (cd "$BACKUP_DIR" && sha256sum -c "$NAME.sql.gz.sha256" >/dev/null 2>&1); then
  fail 79 "Checksum mismatch or missing sidecar for $DUMP"
fi

if ! gzip -t "$DUMP" 2>/dev/null \
  || [ "$(gzip -dc "$DUMP" | tail -n 1 | grep -c '^-- Dump completed' || true)" -ne 1 ]; then
  fail 72 "Dump is invalid (gzip or missing '-- Dump completed' trailer): $DUMP"
fi

if [ -n "$(find "$DUMP" -mmin "+$((MAX_AGE_HOURS * 60))" 2>/dev/null)" ]; then
  fail 73 "Newest backup is older than $MAX_AGE_HOURS hours: $DUMP"
fi

MANIFEST="$BACKUP_DIR/$NAME.manifest"
[ -f "$MANIFEST" ] || fail 72 "Manifest missing: $MANIFEST"
manifest_get() { grep -E "^$1=" "$MANIFEST" | tail -n 1 | cut -d= -f2- | tr -d '\r' || true; }
M_DB="$(manifest_get database)"
M_HOST="$(manifest_get host)"
M_TABLES="$(manifest_get tables)"
M_MIGRATIONS="$(manifest_get schema_migrations)"
if [ "$M_DB" = "$R_DB" ] && [ "$M_HOST" = "$R_HOST" ]; then
  fail 71 "The restore target is the database the backup was taken from; refusing."
fi

if gzip -dc "$DUMP" | grep -m1 -qE '^(USE |CREATE DATABASE)'; then
  fail 74 "Dump contains USE/CREATE DATABASE statements; refusing to restore."
fi

CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
chmod 600 "$CNF"
cat > "$CNF" <<MYSQLCNF
[client]
host=$R_HOST
port=$R_PORT
user=$R_USER
password=$R_PASS
default-character-set=utf8mb4
MYSQLCNF

# tr strips the CR that Windows mysql clients append (harmless elsewhere).
rq() { mysql --defaults-extra-file="$CNF" -N -B "$R_DB" -e "$1" | tr -d '\r'; }

# Drops every table and view in the scratch database ONLY.
wipe_restore_db() {
  local tables stmt="" t
  tables="$(rq "SELECT CONCAT(table_type, ':', table_name) FROM information_schema.tables WHERE table_schema=DATABASE()")" || return 0
  [ -n "$tables" ] || return 0
  while IFS= read -r t; do
    case "$t" in
      "VIEW:"*) stmt="$stmt DROP VIEW IF EXISTS \`${t#VIEW:}\`;" ;;
      *) stmt="$stmt DROP TABLE IF EXISTS \`${t#*:}\`;" ;;
    esac
  done <<< "$tables"
  rq "SET FOREIGN_KEY_CHECKS=0;$stmt SET FOREIGN_KEY_CHECKS=1;" || true
}

wipe_restore_db
trap 'wipe_restore_db; rm -f "$CNF"' EXIT

if ! gzip -dc "$DUMP" | mysql --defaults-extra-file="$CNF" "$R_DB"; then
  fail 75 "Restore into $R_DB failed."
fi

R_TABLES="$(rq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")"
if [ "$R_TABLES" != "$M_TABLES" ]; then
  fail 76 "Table count mismatch: restored $R_TABLES, manifest $M_TABLES."
fi
R_MIGRATIONS=0
if [ "$(rq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'")" -gt 0 ]; then
  R_MIGRATIONS="$(rq "SELECT COUNT(*) FROM schema_migrations")"
fi
if [ "$R_MIGRATIONS" != "$M_MIGRATIONS" ]; then
  fail 77 "schema_migrations mismatch: restored $R_MIGRATIONS, manifest $M_MIGRATIONS."
fi

wipe_restore_db
echo "Restore check OK: $DUMP restored into $R_DB (tables=$R_TABLES, migrations=$R_MIGRATIONS); scratch database wiped."
