#!/usr/bin/env bash
# Daily database backup, run by HOSTING CRON (independent of deploys).
# Usage: bash db-backup.sh --env-file PATH --backup-dir PATH [--retain-daily 14] [--retain-weekly 8]
#
# Writes daily-<APP_ENV>-<ts>.sql.gz + .sha256 + .manifest (mode 600), verifies
# the dump, copies it to weekly-* on Sundays and applies retention.
# Exit codes: 80 bad usage/config, 81 dump failed, 82 integrity check failed,
# 83 another backup is running. Secrets are never printed.
#
# Keep get_env in sync with scripts/deploy/api-remote.sh.
set -Eeuo pipefail
umask 077

ENV_FILE=""
BACKUP_DIR=""
RETAIN_DAILY=14
RETAIN_WEEKLY=8

fail() {
  local code="$1"
  shift
  echo "$*" >&2
  exit "$code"
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --env-file) ENV_FILE="${2:-}"; shift 2 ;;
    --backup-dir) BACKUP_DIR="${2:-}"; shift 2 ;;
    --retain-daily) RETAIN_DAILY="${2:-}"; shift 2 ;;
    --retain-weekly) RETAIN_WEEKLY="${2:-}"; shift 2 ;;
    *) fail 80 "Unknown argument: $1" ;;
  esac
done

[ -n "$ENV_FILE" ] || fail 80 "--env-file is required."
[ -n "$BACKUP_DIR" ] || fail 80 "--backup-dir is required."
case "$BACKUP_DIR" in
  /*) ;;
  *) fail 80 "--backup-dir must be an absolute path." ;;
esac
[ -f "$ENV_FILE" ] || fail 80 "Env file not found: $ENV_FILE"
case "$RETAIN_DAILY" in '' | *[!0-9]* | 0) fail 80 "--retain-daily must be a positive number." ;; esac
case "$RETAIN_WEEKLY" in '' | *[!0-9]* | 0) fail 80 "--retain-weekly must be a positive number." ;; esac

for tool in mysqldump mysql gzip sha256sum find; do
  command -v "$tool" >/dev/null 2>&1 || fail 80 "Required command not found: $tool"
done

get_env() {
  local key="$1" line
  line="$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line%$'\r'}"
  line="${line%\"}"
  line="${line#\"}"
  line="${line%\'}"
  line="${line#\'}"
  printf '%s' "$line"
}

DB_HOST="$(get_env DB_HOST)"
DB_PORT="$(get_env DB_PORT)"
DB_DATABASE="$(get_env DB_DATABASE)"
DB_USERNAME="$(get_env DB_USERNAME)"
DB_PASSWORD="$(get_env DB_PASSWORD)"
APP_ENV="$(get_env APP_ENV)"

if [ -z "$DB_HOST" ] || [ -z "$DB_PORT" ] || [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ] || [ -z "$APP_ENV" ]; then
  fail 80 "Database settings or APP_ENV are incomplete in $ENV_FILE"
fi
case "$APP_ENV" in
  *[!A-Za-z0-9_-]*) fail 80 "APP_ENV contains unsupported characters." ;;
esac

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# Lock: flock when available, otherwise an atomic mkdir.
LOCK_DIR=""
if command -v flock >/dev/null 2>&1; then
  exec 9> "$BACKUP_DIR/.db-backup.lock"
  flock -n 9 || fail 83 "Another db-backup is already running."
else
  LOCK_DIR="$BACKUP_DIR/.db-backup.lockdir"
  mkdir "$LOCK_DIR" 2>/dev/null || fail 83 "Another db-backup is already running."
fi

MYSQL_CNF="$(mktemp)"
PARTIAL=""
cleanup() {
  rm -f "$MYSQL_CNF"
  if [ -n "$PARTIAL" ]; then rm -f "$PARTIAL"; fi
  if [ -n "$LOCK_DIR" ]; then rmdir "$LOCK_DIR" 2>/dev/null || true; fi
}
trap cleanup EXIT
chmod 600 "$MYSQL_CNF"
cat > "$MYSQL_CNF" <<MYSQLCNF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USERNAME
password=$DB_PASSWORD
default-character-set=utf8mb4
MYSQLCNF

# tr strips the CR that Windows mysql clients append (harmless elsewhere).
q() { mysql --defaults-extra-file="$MYSQL_CNF" -N -B "$DB_DATABASE" -e "$1" | tr -d '\r'; }

TABLES="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")" \
  || fail 81 "Cannot query the database for the manifest."
HAS_LEDGER="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'")" \
  || fail 81 "Cannot query the database for the manifest."
MIGRATIONS=0
if [ "$HAS_LEDGER" -gt 0 ]; then
  MIGRATIONS="$(q "SELECT COUNT(*) FROM schema_migrations")" || fail 81 "Cannot read schema_migrations."
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
NAME="daily-${APP_ENV}-${STAMP}"
FINAL="$BACKUP_DIR/$NAME.sql.gz"
PARTIAL="$FINAL.partial"

if ! { mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --no-tablespaces --routines --triggers "$DB_DATABASE" | gzip -c > "$PARTIAL"; }; then
  fail 81 "mysqldump failed; no backup was written."
fi

if ! gzip -t "$PARTIAL" \
  || [ "$(gzip -dc "$PARTIAL" | tail -n 1 | grep -c '^-- Dump completed' || true)" -ne 1 ]; then
  fail 82 "Dump failed the integrity check (gzip or trailer)."
fi
DUMP_TABLES="$(gzip -dc "$PARTIAL" | grep -c '^CREATE TABLE' || true)"
if [ "$DUMP_TABLES" -ne "$TABLES" ]; then
  fail 82 "Dump failed the integrity check (tables in dump: $DUMP_TABLES, in database: $TABLES)."
fi

mv "$PARTIAL" "$FINAL"
PARTIAL=""
(cd "$BACKUP_DIR" && sha256sum "$NAME.sql.gz" > "$NAME.sql.gz.sha256")
{
  echo "database=$DB_DATABASE"
  echo "host=$DB_HOST"
  echo "app_env=$APP_ENV"
  echo "tables=$TABLES"
  echo "schema_migrations=$MIGRATIONS"
  echo "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$BACKUP_DIR/$NAME.manifest"
chmod 600 "$FINAL" "$FINAL.sha256" "$BACKUP_DIR/$NAME.manifest"

if [ "$(date +%u)" = "7" ]; then
  WEEKLY="weekly-${APP_ENV}-${STAMP}"
  cp -p "$FINAL" "$BACKUP_DIR/$WEEKLY.sql.gz"
  cp -p "$FINAL.sha256" "$BACKUP_DIR/$WEEKLY.sql.gz.sha256"
  cp -p "$BACKUP_DIR/$NAME.manifest" "$BACKUP_DIR/$WEEKLY.manifest"
fi

# Retention. Daily sets: by age. Weekly sets: newest N by name.
find "$BACKUP_DIR" -maxdepth 1 -type f -name "daily-${APP_ENV}-*" -mtime "+$((RETAIN_DAILY - 1))" -delete
find "$BACKUP_DIR" -maxdepth 1 -type f -name "weekly-${APP_ENV}-*.sql.gz" | sort -r | tail -n "+$((RETAIN_WEEKLY + 1))" | while IFS= read -r old; do
  base="${old%.sql.gz}"
  rm -f "$old" "$old.sha256" "$base.manifest"
done

SIZE="$(du -h "$FINAL" | cut -f1)"
echo "Backup OK: $FINAL ($SIZE, tables=$TABLES, migrations=$MIGRATIONS)"
