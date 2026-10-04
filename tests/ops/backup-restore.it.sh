#!/usr/bin/env bash
# DB-backed test of scripts/ops/db-backup.sh and db-restore-check.sh.
# CI: MySQL 8.4 service, source DB zmierzymy_czas_test (migrated and seeded),
# empty scratch DB zmierzymy_czas_restore_check. Locally override with
# IT_DB_HOST IT_DB_PORT IT_DB_USER IT_DB_PASS IT_SOURCE_DB IT_RESTORE_DB
# (never point these at a database that holds real data).
set -Eeuo pipefail
cd "$(dirname "$0")/../.."
# shellcheck source=tests/ops/lib.sh
source tests/ops/lib.sh
require_tools bash mysql mysqldump gzip sha256sum find touch

HOST="${IT_DB_HOST:-127.0.0.1}"
PORT="${IT_DB_PORT:-3306}"
DBUSER="${IT_DB_USER:-root}"
DBPASS="${IT_DB_PASS:-}"
SRC_DB="${IT_SOURCE_DB:-zmierzymy_czas_test}"
RESTORE_DB="${IT_RESTORE_DB:-zmierzymy_czas_restore_check}"

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

cat > "$T/source.env" <<ENVF
APP_ENV=local
DB_HOST=$HOST
DB_PORT=$PORT
DB_DATABASE=$SRC_DB
DB_USERNAME=$DBUSER
DB_PASSWORD=$DBPASS
ENVF

write_restore_env() { # DBNAME FILE
  cat > "$2" <<ENVF
RESTORE_DB_HOST=$HOST
RESTORE_DB_PORT=$PORT
RESTORE_DB_DATABASE=$1
RESTORE_DB_USERNAME=$DBUSER
RESTORE_DB_PASSWORD=$DBPASS
ENVF
}
write_restore_env "$RESTORE_DB" "$T/restore.env"
write_restore_env "$SRC_DB" "$T/restore-wrong.env"

backup() { bash scripts/ops/db-backup.sh --env-file "$T/source.env" --backup-dir "$1"; }
restore() { bash scripts/ops/db-restore-check.sh --backup-dir "$1" --restore-env-file "${2:-$T/restore.env}" "${@:3}"; }
count_tables() {
  MYSQL_PWD="$DBPASS" mysql -h"$HOST" -P"$PORT" -u"$DBUSER" -N -B "$RESTORE_DB" \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()"
}
fresh() { rm -rf "$T/daily"; mkdir -p "$T/daily"; }
newest() { find "$1" -maxdepth 1 -name 'daily-*.sql.gz' | sort | tail -n 1; }

# (a) backup
fresh
assert_exit 0 backup "$T/daily"
assert_contains "backup summary" "Backup OK:"
DUMP="$(newest "$T/daily")"
NAME="$(basename "$DUMP" .sql.gz)"
assert_file_exists "dump exists" "$DUMP"
assert_file_exists "sha256 sidecar exists" "$DUMP.sha256"
assert_file_exists "manifest exists" "$T/daily/$NAME.manifest"
assert_file_mode 600 "$DUMP"
assert_file_mode 600 "$DUMP.sha256"
assert_file_mode 600 "$T/daily/$NAME.manifest"
TABLES="$(grep '^tables=' "$T/daily/$NAME.manifest" | cut -d= -f2)"
MIGR="$(grep '^schema_migrations=' "$T/daily/$NAME.manifest" | cut -d= -f2)"
if [ "$TABLES" -gt 40 ]; then pass "manifest tables=$TABLES (>40)"; else fail "manifest tables=$TABLES, expected >40"; fi
check_eq "manifest schema_migrations equals migration files" "$(ls database/migrations/*.sql | wc -l | tr -d ' ')" "$MIGR"
check_eq "dump trailer present" "1" "$(gzip -dc "$DUMP" | tail -n 1 | grep -c '^-- Dump completed')"

if [ "$(date +%u)" = "7" ]; then
  check_eq "Sunday: weekly copy written" "1" "$(find "$T/daily" -maxdepth 1 -name 'weekly-local-*.sql.gz' | wc -l | tr -d ' ')"
fi

# (b) restore-check, then the scratch DB is empty again
assert_exit 0 restore "$T/daily"
assert_contains "restore summary" "Restore check OK:"
assert_contains "restore reports table count" "tables=$TABLES"
check_eq "scratch database wiped (0 tables)" "0" "$(count_tables)"

# (c) scratch name without 'restore' is refused before anything else
assert_exit 70 restore "$T/daily" "$T/restore-wrong.env"
check_eq "source database untouched by refused restore" "$TABLES" \
  "$(MYSQL_PWD="$DBPASS" mysql -h"$HOST" -P"$PORT" -u"$DBUSER" -N -B "$SRC_DB" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")"

# (d) newer dump without the trailer, valid checksum -> 72
cp -r "$T/daily" "$T/daily-d"
NEW="daily-local-99991231-235959"
gzip -dc "$DUMP" | grep -v '^-- Dump completed' | gzip -c > "$T/daily-d/$NEW.sql.gz"
(cd "$T/daily-d" && sha256sum "$NEW.sql.gz" > "$NEW.sql.gz.sha256")
cp "$T/daily/$NAME.manifest" "$T/daily-d/$NEW.manifest"
assert_exit 72 restore "$T/daily-d"
check_eq "scratch database still empty after refused dump" "0" "$(count_tables)"

# (e) corrupt sidecar -> 79
cp -r "$T/daily" "$T/daily-e"
echo "0000000000000000000000000000000000000000000000000000000000000000  $NAME.sql.gz" > "$T/daily-e/$NAME.sql.gz.sha256"
assert_exit 79 restore "$T/daily-e"

# (f) stale dump -> 73
cp -r "$T/daily" "$T/daily-f"
touch -d '30 hours ago' "$T/daily-f/$NAME.sql.gz" "$T/daily-f/$NAME.sql.gz.sha256" "$T/daily-f/$NAME.manifest"
assert_exit 73 restore "$T/daily-f" "$T/restore.env" --max-age-hours 26
assert_exit 0 restore "$T/daily-f" "$T/restore.env" --max-age-hours 48

# (g) manifest says the dump came from the restore target -> 71
cp -r "$T/daily" "$T/daily-g"
sed -i "s/^database=.*/database=$RESTORE_DB/; s/^host=.*/host=$HOST/" "$T/daily-g/$NAME.manifest"
assert_exit 71 restore "$T/daily-g"

# (g2) manifest table count that does not match the restored data -> 76
cp -r "$T/daily" "$T/daily-g2"
sed -i "s/^tables=.*/tables=$((TABLES + 1))/" "$T/daily-g2/$NAME.manifest"
assert_exit 76 restore "$T/daily-g2"
check_eq "scratch database wiped after failed comparison" "0" "$(count_tables)"

# (g3) no dump at all -> 78
mkdir -p "$T/empty"
assert_exit 78 restore "$T/empty"

# (h) a second backup while the lock is held -> 83
fresh
if command -v flock >/dev/null 2>&1; then
  (exec 9> "$T/daily/.db-backup.lock"; flock 9; sleep 20) &
  HOLDER=$!
  sleep 1
else
  mkdir "$T/daily/.db-backup.lockdir"
  HOLDER=""
fi
assert_exit 83 backup "$T/daily"
if [ -n "$HOLDER" ]; then kill "$HOLDER" 2>/dev/null || true; wait "$HOLDER" 2>/dev/null || true; fi
check_eq "no dump written while locked" "0" "$(find "$T/daily" -maxdepth 1 -name 'daily-*' | wc -l | tr -d ' ')"

# (i) retention: old daily sets are removed, recent ones kept
fresh
for i in 1 2; do
  : > "$T/daily/daily-local-2020010$i-000000.sql.gz"
  touch -d '40 days ago' "$T/daily/daily-local-2020010$i-000000.sql.gz"
done
assert_exit 0 backup "$T/daily"
check_eq "old daily dumps removed, new one kept" "1" "$(find "$T/daily" -maxdepth 1 -name 'daily-*.sql.gz' | wc -l | tr -d ' ')"

# (j) bad usage
assert_exit 80 bash scripts/ops/db-backup.sh --env-file "$T/source.env"
assert_exit 80 bash scripts/ops/db-backup.sh --env-file "$T/nope.env" --backup-dir "$T/daily"

finish
