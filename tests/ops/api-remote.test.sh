#!/usr/bin/env bash
# Tests scripts/deploy/api-remote.sh the way CI runs it: piped through `bash -s`.
# php and mysqldump are stubs; everything else (tar, gzip, unzip, realpath) is real.
set -Eeuo pipefail
cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
# shellcheck source=tests/ops/lib.sh
source tests/ops/lib.sh
require_tools bash tar gzip unzip realpath sha256sum sort find

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

STUBS="$T/stubs"
mkdir -p "$STUBS"
cat > "$STUBS/php" <<'STUB'
#!/usr/bin/env bash
echo "$*" >> "$STUB_LOG_DIR/php.log"
case "$*" in
  *check-config.php*) exit "${STUB_CHECKCONFIG_EXIT:-0}" ;;
  *migrate.php*) exit "${STUB_MIGRATE_EXIT:-0}" ;;
esac
exit 0
STUB
cat > "$STUBS/mysqldump" <<'STUB'
#!/usr/bin/env bash
echo "$*" >> "$STUB_LOG_DIR/mysqldump.log"
echo "CREATE TABLE t (id int);"
echo "-- Dump completed on 2026-10-05"
STUB
chmod +x "$STUBS/php" "$STUBS/mysqldump"

# make_zip SRC_DIR OUT_ZIP (zip when available, else python zipfile)
make_zip() {
  if command -v zip >/dev/null 2>&1; then
    (cd "$1" && zip -r -q "$2" .)
    return
  fi
  local py
  py="$(command -v python || command -v python3)"
  winpath() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s' "$1"; fi; }
  cat > "$T/mkzip.py" <<'PY'
import os, sys, zipfile
src, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, "w") as z:
    for base, _, files in os.walk(src):
        for f in files:
            p = os.path.join(base, f)
            z.write(p, os.path.relpath(p, src))
PY
  "$py" "$(winpath "$T/mkzip.py")" "$(winpath "$1")" "$(winpath "$2")"
}

# fixture NAME : builds a fresh host layout under $T/NAME and exports the paths.
fixture() {
  F="$T/$1"
  export HOME_DIR="$F/home/u"
  ALLOWED="$HOME_DIR/domains"
  REMOTE="$ALLOWED/api"
  BACKUP="$F/backups"
  LOGS="$F/stublogs"
  mkdir -p "$REMOTE/src" "$REMOTE/public/uploads" "$REMOTE/storage/logs" "$REMOTE/bin" "$BACKUP" "$LOGS"
  : > "$REMOTE/.zmierzymy-czas-api-root"
  printf 'APP_ENV=staging\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=zc_x\nDB_USERNAME=u\nDB_PASSWORD=pw-secret-1\n' > "$REMOTE/.env"
  printf 'v1' > "$REMOTE/src/Old.php"
  printf 'DEPLOY_ID=staging-1-aaaaaaa\n' > "$REMOTE/.zmierzymy-czas-release"
  printf 'jpg' > "$REMOTE/public/uploads/a.jpg"
  # Zip of release 2.
  local rel="$F/release2"
  mkdir -p "$rel/src" "$rel/bin"
  printf 'v2' > "$rel/src/Old.php"
  printf 'new' > "$rel/src/New.php"
  printf '<?php' > "$rel/bin/check-config.php"
  printf 'DEPLOY_ID=staging-2-bbbbbbb\n' > "$rel/.zmierzymy-czas-release"
  mkdir -p "$REMOTE/.deploy"
  make_zip "$rel" "$REMOTE/.deploy/api-staging-2-bbbbbbb.zip"
  : > "$LOGS/php.log"
  : > "$LOGS/mysqldump.log"
}

# remote CMD [ARG] : feeds the script exactly like the workflow does.
remote() {
  env -i PATH="$STUBS:$PATH" HOME="$HOME_DIR" STUB_LOG_DIR="$LOGS" \
    STUB_CHECKCONFIG_EXIT="${STUB_CHECKCONFIG_EXIT:-0}" STUB_MIGRATE_EXIT="${STUB_MIGRATE_EXIT:-0}" \
    BACKEND_REMOTE_DIR="${R_DIR:-$REMOTE}" BACKEND_ALLOWED_ROOT="${R_ALLOWED:-$ALLOWED}" \
    BACKEND_BACKUP_DIR="$BACKUP" TARGET_ENV="${R_TARGET:-staging}" \
    DEPLOY_ID="${R_DEPLOY_ID:-staging-2-bbbbbbb}" MIGRATION_MODE="${R_MODE:-run}" \
    bash -s -- "$@" < "$ROOT/scripts/deploy/api-remote.sh"
}

tree_sum() { (cd "$1" && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1); }
content() { cat "$1"; }
count_glob() { local n=0 f; for f in "$@"; do [ -e "$f" ] && n=$((n + 1)); done; echo "$n"; }

# (a) preflight OK
fixture a
assert_exit 0 remote preflight
assert_contains "preflight message" "Preflight OK for staging"

# (b) REMOTE outside ALLOWED
fixture b
mkdir -p "$F/elsewhere"
R_ALLOWED="$F/elsewhere" assert_exit 17 remote preflight

# (c) missing marker
fixture c
rm -f "$REMOTE/.zmierzymy-czas-api-root"
assert_exit 18 remote preflight
fixture c2
rm -f "$REMOTE/.env"
assert_exit 19 remote preflight

# (d) TARGET_ENV vs .env mismatch fails before anything is written
fixture d
R_TARGET=production R_MODE=run assert_exit 25 remote install
check_eq "no predeploy dump after env mismatch" "0" "$(count_glob "$BACKUP"/predeploy-*)"
check_eq "no code snapshot after env mismatch" "0" "$(count_glob "$BACKUP"/code/*)"
check_eq "code untouched after env mismatch" "v1" "$(content "$REMOTE/src/Old.php")"

# (e) happy install
fixture e
BEFORE_ENV="$(sha256sum < "$REMOTE/.env")"
assert_exit 0 remote install
assert_contains "deploy summary names previous release" "previous release: staging-1-aaaaaaa"
check_eq "code replaced with v2" "v2" "$(content "$REMOTE/src/Old.php")"
check_eq "new file present" "new" "$(content "$REMOTE/src/New.php")"
check_eq "marker updated" "DEPLOY_ID=staging-2-bbbbbbb" "$(content "$REMOTE/.zmierzymy-czas-release")"
check_eq "exactly one code snapshot" "1" "$(count_glob "$BACKUP"/code/code-*-staging-1-aaaaaaa.tar.gz)"
SNAP="$(ls "$BACKUP"/code/code-*-staging-1-aaaaaaa.tar.gz)"
LIST="$(tar -tzf "$SNAP")"
OUT="$LIST"
assert_contains "snapshot has source" "./src/Old.php"
assert_not_contains "snapshot has no .env" "./.env"
assert_not_contains "snapshot has no uploads" "./public/uploads/a.jpg"
assert_not_contains "snapshot has no storage" "./storage"
assert_not_contains "snapshot has no .deploy" "./.deploy"
assert_file_mode 600 "$SNAP"
check_eq "one predeploy dump" "1" "$(count_glob "$BACKUP"/predeploy-staging-*.sql.gz)"
assert_file_mode 600 "$(ls "$BACKUP"/predeploy-staging-*.sql.gz)"
OUT="$(cat "$LOGS/mysqldump.log")"
assert_contains "mysqldump uses --no-tablespaces" "--no-tablespaces"
assert_contains "mysqldump uses --single-transaction" "--single-transaction"
check_eq "php order: check-config then migrate" "check-config.php migrate.php" \
  "$(sed -E 's#.*/bin/([a-z-]+\.php).*#\1#' "$LOGS/php.log" | tr '\n' ' ' | sed 's/ $//')"
assert_file_absent "uploaded zip removed" "$REMOTE/.deploy/api-staging-2-bbbbbbb.zip"
check_eq ".env unchanged" "$BEFORE_ENV" "$(sha256sum < "$REMOTE/.env")"
check_eq "uploads unchanged" "jpg" "$(content "$REMOTE/public/uploads/a.jpg")"

# (i) list changes nothing (state after e)
BEFORE_TREE="$(tree_sum "$F")"
assert_exit 0 remote rollback list
assert_contains "list shows the snapshot" "code-"
check_eq "list changed nothing" "$BEFORE_TREE" "$(tree_sum "$F")"

# (j) rollback latest, no migrations
: > "$LOGS/php.log"
assert_exit 0 remote rollback latest
check_eq "rollback restored v1" "v1" "$(content "$REMOTE/src/Old.php")"
check_eq "rollback restored marker" "DEPLOY_ID=staging-1-aaaaaaa" "$(content "$REMOTE/.zmierzymy-czas-release")"
check_eq "one prerollback snapshot" "1" "$(count_glob "$BACKUP"/code/prerollback-*)"
if grep -q migrate.php "$LOGS/php.log"; then fail "rollback must not run migrations"; else pass "rollback ran no migrations"; fi
check_eq "uploads unchanged by rollback" "jpg" "$(content "$REMOTE/public/uploads/a.jpg")"
check_eq ".env unchanged by rollback" "$BEFORE_ENV" "$(sha256sum < "$REMOTE/.env")"
assert_contains "rollback message mentions undo" "Undo a rollback"

# (k) rollback names
assert_exit 41 remote rollback '../../etc/passwd'
assert_exit 41 remote rollback ''
assert_exit 41 remote rollback 'prerollback-20261005-101500-x.tar.gz'
assert_exit 40 remote rollback 'code-20200101-000000-nothing.tar.gz'
fixture k
assert_exit 0 remote rollback list
assert_contains "no snapshots message" "No code snapshots found."
assert_exit 40 remote rollback latest

# (f) migration failure: previous code is restored, exit 22
fixture f
BEFORE_ENV="$(sha256sum < "$REMOTE/.env")"
STUB_MIGRATE_EXIT=1 assert_exit 22 remote install
assert_contains "message names the dump" "predeploy-staging-"
check_eq "migration failure restored v1" "v1" "$(content "$REMOTE/src/Old.php")"
check_eq "migration failure restored marker" "DEPLOY_ID=staging-1-aaaaaaa" "$(content "$REMOTE/.zmierzymy-czas-release")"
check_eq ".env unchanged after failed migration" "$BEFORE_ENV" "$(sha256sum < "$REMOTE/.env")"
check_eq "uploads unchanged after failed migration" "jpg" "$(content "$REMOTE/public/uploads/a.jpg")"
check_eq "predeploy dump kept for the operator" "1" "$(count_glob "$BACKUP"/predeploy-staging-*.sql.gz)"

# (g) check-config failure: restored, migrations never run, exit 28
fixture g
STUB_CHECKCONFIG_EXIT=1 assert_exit 28 remote install
check_eq "check-config failure restored v1" "v1" "$(content "$REMOTE/src/Old.php")"
if grep -q migrate.php "$LOGS/php.log"; then fail "migrate must not run after check-config failure"; else pass "no migrate after check-config failure"; fi

# (h) retention
fixture h
mkdir -p "$BACKUP/code"
for i in 1 2 3 4 5 6 7; do : > "$BACKUP/code/code-2020010${i}-000000-x.tar.gz"; done
for i in $(seq -w 1 12); do : > "$BACKUP/predeploy-staging-202001${i}-000000.sql.gz"; done
assert_exit 0 remote install
check_eq "keeps 5 code snapshots" "5" "$(count_glob "$BACKUP"/code/code-*.tar.gz)"
check_eq "keeps 10 predeploy dumps" "10" "$(count_glob "$BACKUP"/predeploy-staging-*.sql.gz)"
check_eq "newest snapshot kept" "1" "$(count_glob "$BACKUP"/code/code-*-staging-1-aaaaaaa.tar.gz)"
check_eq "oldest snapshot pruned" "0" "$(count_glob "$BACKUP"/code/code-20200101-*)"

# (l) bad migration mode writes nothing
fixture l
R_MODE=bogus assert_exit 23 remote install
check_eq "no dump for bad mode" "0" "$(count_glob "$BACKUP"/predeploy-*)"
check_eq "no snapshot for bad mode" "0" "$(count_glob "$BACKUP"/code/*)"

# missing zip, unknown subcommand
fixture n
rm -f "$REMOTE/.deploy/api-staging-2-bbbbbbb.zip"
assert_exit 37 remote install
check_eq "no dump when the artifact is missing" "0" "$(count_glob "$BACKUP"/predeploy-*)"
assert_exit 2 remote bogus

# secrets are never printed
fixture s
STUB_MIGRATE_EXIT=1 assert_exit 22 remote install
assert_not_contains "DB password never printed" "pw-secret-1"

# (m) missing tool -> 36, via the function under ZC_API_REMOTE_LIB_ONLY
check_tool() {
  ZC_API_REMOTE_LIB_ONLY=1 bash -c 'source scripts/deploy/api-remote.sh; require_cmd bash definitely-not-a-real-tool-xyz'
}
assert_exit 36 check_tool
assert_contains "names the missing tool" "definitely-not-a-real-tool-xyz"

finish
