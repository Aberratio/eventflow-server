#!/usr/bin/env bash
# Runs every tests/ops/*.test.sh and exits non-zero if any of them fails.
# (backup-restore.it.sh needs MySQL and is run by its own CI job.)
set -Eeuo pipefail
cd "$(dirname "$0")/../.."

status=0
total_pass=0
for t in tests/ops/*.test.sh; do
  echo "=== $t"
  log="$(mktemp)"
  if ! bash "$t" >"$log" 2>&1; then
    status=1
  fi
  cat "$log"
  n="$(grep -Eo '^[0-9]+ passed' "$log" | tail -n 1 | cut -d' ' -f1 || true)"
  total_pass=$((total_pass + ${n:-0}))
  rm -f "$log"
done
echo "=== tests/ops total: $total_pass passed, status=$status"
exit "$status"
