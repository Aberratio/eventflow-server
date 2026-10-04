#!/usr/bin/env bash
# Remote half of the API deploy and rollback. It runs ON THE HOST and is piped
# over ssh stdin, so it is never executed from inside the tree it overwrites:
#
#   ssh ... "$REMOTE_ENV bash -s -- <preflight|install|rollback> [snapshot]" < scripts/deploy/api-remote.sh
#
# Environment (passed by the workflow with printf %q):
#   BACKEND_REMOTE_DIR BACKEND_ALLOWED_ROOT BACKEND_BACKUP_DIR TARGET_ENV DEPLOY_ID
#   install only: REMOTE_ZIP MIGRATION_MODE
#
# Exit codes: see docs/RUNBOOK.md. Secrets are never printed and `set -x` is
# never used. The database is NEVER restored automatically.
#
# Keep this script's get_env in sync with scripts/ops/db-backup.sh.
set -Eeuo pipefail
umask 077

REMOTE_DIR="${BACKEND_REMOTE_DIR:-}"
ALLOWED_ROOT="${BACKEND_ALLOWED_ROOT:-}"
BACKUP_DIR="${BACKEND_BACKUP_DIR:-}"
TARGET_ENV="${TARGET_ENV:-}"
DEPLOY_ID="${DEPLOY_ID:-}"
MIGRATION_MODE="${MIGRATION_MODE:-}"

REMOTE_REAL=""
ALLOWED_REAL=""
BACKUP_REAL=""
ENV_FILE=""
DB_HOST="" DB_PORT="" DB_DATABASE="" DB_USERNAME="" DB_PASSWORD="" APP_ENV=""
UPLOAD_DIR=""
MYSQL_CNF=""

fail() {
  local code="$1"
  shift
  echo "$*" >&2
  exit "$code"
}

require_cmd() {
  local name
  for name in "$@"; do
    if ! command -v "$name" >/dev/null 2>&1; then
      fail 36 "Required command not found on host: $name"
    fi
  done
}

resolve_paths() {
  [ -n "$REMOTE_DIR" ] || fail 10 "BACKEND_REMOTE_DIR is required."
  [ -n "$ALLOWED_ROOT" ] || fail 11 "BACKEND_ALLOWED_ROOT is required."
  case "$ALLOWED_ROOT" in
    /*) ;;
    *) fail 27 "BACKEND_ALLOWED_ROOT must be an absolute path." ;;
  esac
  [ -n "$BACKUP_DIR" ] || fail 12 "BACKEND_BACKUP_DIR is required."

  if [ "$REMOTE_DIR" = "/" ] || [ "$REMOTE_DIR" = "." ] || [ "$REMOTE_DIR" = "${HOME:-}" ] || [ "$REMOTE_DIR" = "~" ]; then
    fail 13 "BACKEND_REMOTE_DIR points to an unsafe location: $REMOTE_DIR"
  fi
  case "$REMOTE_DIR" in
    *..*) fail 14 "BACKEND_REMOTE_DIR cannot contain '..'." ;;
  esac
  case "$BACKUP_DIR" in
    /*) ;;
    *) fail 15 "BACKEND_BACKUP_DIR must be an absolute path." ;;
  esac

  require_cmd realpath
  REMOTE_REAL="$(realpath -e "$REMOTE_DIR")" || fail 16 "BACKEND_REMOTE_DIR does not exist: $REMOTE_DIR"
  ALLOWED_REAL="$(realpath -e "$ALLOWED_ROOT")" || fail 17 "BACKEND_ALLOWED_ROOT does not exist: $ALLOWED_ROOT"

  case "$REMOTE_REAL" in
    / | . | "${HOME:-}")
      fail 16 "BACKEND_REMOTE_DIR points to an unsafe location: $REMOTE_REAL"
      ;;
  esac

  case "$REMOTE_REAL/" in
    "$ALLOWED_REAL"/* | "$ALLOWED_REAL/") ;;
    *)
      echo "BACKEND_REMOTE_DIR_REAL=$REMOTE_REAL" >&2
      echo "BACKEND_ALLOWED_ROOT_REAL=$ALLOWED_REAL" >&2
      fail 17 "BACKEND_REMOTE_DIR is outside BACKEND_ALLOWED_ROOT."
      ;;
  esac

  [ -f "$REMOTE_REAL/.zmierzymy-czas-api-root" ] || fail 18 "Missing deploy marker: $REMOTE_REAL/.zmierzymy-czas-api-root"
  [ -f "$REMOTE_REAL/.env" ] || fail 19 "Missing backend env file: $REMOTE_REAL/.env"
  ENV_FILE="$REMOTE_REAL/.env"

  mkdir -p "$REMOTE_REAL/.deploy" "$BACKUP_DIR"
  chmod 700 "$BACKUP_DIR"
  BACKUP_REAL="$(cd "$BACKUP_DIR" && pwd -P)"

  case "$BACKUP_REAL/" in
    "$REMOTE_REAL"/* | "$REMOTE_REAL/")
      echo "BACKEND_BACKUP_DIR_REAL=$BACKUP_REAL" >&2
      fail 24 "BACKEND_BACKUP_DIR must be outside BACKEND_REMOTE_DIR."
      ;;
  esac
}

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

load_env() {
  DB_HOST="$(get_env DB_HOST)"
  DB_PORT="$(get_env DB_PORT)"
  DB_DATABASE="$(get_env DB_DATABASE)"
  DB_USERNAME="$(get_env DB_USERNAME)"
  DB_PASSWORD="$(get_env DB_PASSWORD)"
  APP_ENV="$(get_env APP_ENV)"
  UPLOAD_DIR="$(get_env APP_UPLOAD_DIR)"
  [ -n "$UPLOAD_DIR" ] || UPLOAD_DIR="public/uploads"

  local log_dir log_real test_file
  log_dir="$(get_env LOG_DIR)"
  [ -n "$log_dir" ] || log_dir="$REMOTE_REAL/storage/logs"
  mkdir -p "$log_dir"
  chmod 700 "$log_dir"
  log_real="$(cd "$log_dir" && pwd -P)"
  case "$log_real/" in
    "$REMOTE_REAL/public/"* | "$REMOTE_REAL/public_html/"*)
      fail 35 "LOG_DIR must be outside public web directories."
      ;;
  esac
  test_file="$log_real/.deploy-write-test"
  printf '%s\n' "${DEPLOY_ID:-check}" > "$test_file"
  chmod 600 "$test_file"
  rm -f "$test_file"

  if [ "$TARGET_ENV" != "$APP_ENV" ]; then
    fail 25 "TARGET_ENV=$TARGET_ENV but APP_ENV=$APP_ENV in $ENV_FILE"
  fi
  if [ -z "$DB_HOST" ] || [ -z "$DB_PORT" ] || [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
    fail 20 "Database settings are incomplete in $ENV_FILE"
  fi
  if [ "$TARGET_ENV" = "production" ] && [ -z "$DB_PASSWORD" ]; then
    fail 26 "DB_PASSWORD is empty; refusing production deploy."
  fi
}

cleanup() {
  if [ -n "$MYSQL_CNF" ]; then
    rm -f "$MYSQL_CNF"
  fi
}

write_mysql_cnf() {
  MYSQL_CNF="$(mktemp)"
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
}

# Echoes one tar --exclude argument per line.
upload_excludes() {
  printf '%s\n' \
    "--exclude=./.env" \
    "--exclude=./.env.*" \
    "--exclude=./.deploy" \
    "--exclude=./storage" \
    "--exclude=./public/uploads"
  local rel=""
  case "$UPLOAD_DIR" in
    /*)
      case "$UPLOAD_DIR/" in
        "$REMOTE_REAL"/*) rel="${UPLOAD_DIR#"$REMOTE_REAL"/}" ;;
      esac
      ;;
    *) rel="$UPLOAD_DIR" ;;
  esac
  rel="${rel%/}"
  case "$rel" in
    '' | *..*) ;;
    *) printf '%s\n' "--exclude=./$rel" ;;
  esac
}

current_release_id() {
  local id=""
  if [ -f "$REMOTE_REAL/.zmierzymy-czas-release" ]; then
    id="$(grep -E '^DEPLOY_ID=' "$REMOTE_REAL/.zmierzymy-czas-release" | tail -n 1 | cut -d= -f2- || true)"
    id="$(printf '%s' "$id" | tr -cd 'A-Za-z0-9._-' | cut -c1-80)"
  fi
  printf '%s' "${id:-unknown}"
}

# snapshot_code PREFIX : echoes the path of the new snapshot.
snapshot_code() {
  local prefix="$1" dir file tmp
  dir="$BACKUP_REAL/code"
  mkdir -p "$dir"
  chmod 700 "$dir"
  file="$dir/${prefix}-$(date +%Y%m%d-%H%M%S)-$(current_release_id).tar.gz"
  tmp="$file.partial"
  local -a excludes=()
  while IFS= read -r line; do
    excludes+=("$line")
  done < <(upload_excludes)
  if ! tar -czf "$tmp" -C "$REMOTE_REAL" "${excludes[@]}" . </dev/null \
    || ! gzip -t "$tmp" \
    || ! mv "$tmp" "$file"; then
    rm -f "$tmp"
    fail 29 "Code snapshot failed; nothing was changed."
  fi
  chmod 600 "$file"
  printf '%s' "$file"
}

# prune DIR KEEP GLOB : keeps the newest KEEP files (names start with a timestamp).
prune() {
  local dir="$1" keep="$2" glob="$3" f
  # shellcheck disable=SC2086
  ls -1 "$dir"/$glob 2>/dev/null | sort -r | tail -n +$((keep + 1)) | while IFS= read -r f; do
    rm -f "$f"
  done
}

restore_snapshot() {
  local file="$1"
  if ! gzip -t "$file"; then
    fail 42 "Snapshot is corrupt, cannot restore: $file"
  fi
  rm -f "$REMOTE_REAL/.zmierzymy-czas-release"
  if ! tar -xzf "$file" -C "$REMOTE_REAL" </dev/null; then
    fail 42 "Restoring the snapshot failed: $file"
  fi
  echo "Code restored from $(basename "$file")"
}

cmd_preflight() {
  require_cmd realpath php unzip tar gzip mysqldump
  resolve_paths
  load_env
  echo "Preflight OK for $TARGET_ENV"
}

cmd_install() {
  require_cmd realpath php unzip tar gzip mysqldump
  case "$MIGRATION_MODE" in
    run | skip) ;;
    *) fail 23 "Unsupported MIGRATION_MODE: $MIGRATION_MODE" ;;
  esac
  [ -n "$DEPLOY_ID" ] || fail 37 "DEPLOY_ID is required."
  resolve_paths
  load_env
  write_mysql_cnf

  local zip="$REMOTE_REAL/.deploy/api-${DEPLOY_ID}.zip"
  [ -f "$zip" ] || fail 37 "Uploaded artifact not found: $zip"

  local previous dump snap
  previous="$(current_release_id)"

  dump="$BACKUP_REAL/predeploy-$TARGET_ENV-$(date +%Y%m%d-%H%M%S).sql.gz"
  if ! { mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --no-tablespaces --routines --triggers "$DB_DATABASE" </dev/null | gzip -c > "$dump"; } \
    || ! gzip -t "$dump"; then
    rm -f "$dump"
    fail 21 "Database backup failed or is invalid; nothing was deployed."
  fi
  chmod 600 "$dump"
  echo "Database backup created: $dump"
  prune "$BACKUP_REAL" 10 "predeploy-$TARGET_ENV-*.sql.gz"

  snap="$(snapshot_code code)"
  echo "Code snapshot created: $snap"
  prune "$BACKUP_REAL/code" 5 'code-*.tar.gz'

  if ! unzip -o -q "$zip" -d "$REMOTE_REAL" </dev/null; then
    restore_snapshot "$snap"
    fail 38 "Unzip failed; previous code restored."
  fi
  rm -f "$zip"

  if ! php "$REMOTE_REAL/bin/check-config.php" </dev/null; then
    restore_snapshot "$snap"
    fail 28 "New release refused by bin/check-config.php; previous code restored. Fix the server .env and redeploy."
  fi

  if [ "$MIGRATION_MODE" = "run" ]; then
    if ! php "$REMOTE_REAL/bin/migrate.php" </dev/null; then
      restore_snapshot "$snap"
      fail 22 "Migrations failed; previous code restored. The database may be partially migrated. Pre-deploy dump: $dump. See docs/RUNBOOK.md \"Database rollback\"."
    fi
  else
    echo "Migrations skipped by workflow input."
  fi

  echo "Deployed $DEPLOY_ID (previous release: $previous). Code snapshot: $snap. Database backup: $dump."
}

# list_snapshots DIR : code-* snapshot names, newest first.
list_snapshots() {
  local f
  for f in "$1"/code-*.tar.gz; do
    if [ -e "$f" ]; then basename "$f"; fi
  done | sort -r
}

cmd_rollback() {
  local want="${1:-}"
  if ! [[ "$want" =~ ^(latest|list|code-[0-9]{8}-[0-9]{6}-[A-Za-z0-9._-]{1,80}\.tar\.gz)$ ]]; then
    fail 41 "snapshot must be latest, list or a code-YYYYmmdd-HHMMSS-<id>.tar.gz name."
  fi
  require_cmd realpath php tar gzip
  resolve_paths
  load_env

  local dir="$BACKUP_REAL/code" target pre f
  if [ "$want" = "list" ]; then
    local found=0
    if [ -d "$dir" ]; then
      while IFS= read -r f; do
        found=1
        printf '%s  %s\n' "$f" "$(du -h "$dir/$f" | cut -f1)"
      done < <(list_snapshots "$dir")
    fi
    [ "$found" -eq 1 ] || echo "No code snapshots found."
    return 0
  fi

  if [ "$want" = "latest" ]; then
    target=""
    if [ -d "$dir" ]; then
      target="$(list_snapshots "$dir" | head -n 1 || true)"
    fi
    [ -n "$target" ] || fail 40 "No code snapshot to roll back to."
  else
    target="$want"
  fi
  [ -f "$dir/$target" ] || fail 40 "Snapshot not found: $target"

  pre="$(snapshot_code prerollback)"
  restore_snapshot "$dir/$target"
  prune "$dir" 5 'prerollback-*.tar.gz'

  if [ -f "$REMOTE_REAL/bin/check-config.php" ]; then
    if ! php "$REMOTE_REAL/bin/check-config.php" </dev/null; then
      fail 39 "Rolled back to $target, but bin/check-config.php fails. Code stays rolled back; the state before the rollback is $(basename "$pre")."
    fi
  fi
  echo "Rolled back to $target. To undo, see docs/RUNBOOK.md \"Undo a rollback\" (snapshot $(basename "$pre"))."
}

main() {
  local cmd="${1:-}"
  shift || true
  case "$cmd" in
    preflight) cmd_preflight ;;
    install) cmd_install ;;
    rollback) cmd_rollback "${1:-}" ;;
    *) fail 2 "Usage: api-remote.sh preflight|install|rollback [snapshot]" ;;
  esac
}

# The dispatch is the last line: bash reads piped scripts incrementally, so
# nothing may follow it. ZC_API_REMOTE_LIB_ONLY=1 lets tests source the functions.
if [ -z "${ZC_API_REMOTE_LIB_ONLY:-}" ]; then main "$@"; fi
