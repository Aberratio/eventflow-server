#!/usr/bin/env bash
# Mini assertion library for the ops shell tests. Source it, do not execute it.
# shellcheck disable=SC2034
PASSED=0
FAILED=0
OUT=""
RC=0

pass() {
  PASSED=$((PASSED + 1))
  echo "PASS: $1"
}

fail() {
  FAILED=$((FAILED + 1))
  echo "FAIL: $1" >&2
}

# assert_exit EXPECTED CMD... : runs CMD, captures stdout+stderr in $OUT and the
# exit code in $RC. The test name is the command line.
assert_exit() {
  local expected="$1"
  shift
  set +e
  OUT="$("$@" 2>&1)"
  RC=$?
  set -e
  if [ "$RC" -eq "$expected" ]; then
    pass "exit $expected: $*"
  else
    fail "expected exit $expected, got $RC: $*"
    printf '%s\n' "$OUT" | sed 's/^/    | /' >&2
  fi
}

# check_eq NAME EXPECTED ACTUAL
check_eq() {
  if [ "$2" = "$3" ]; then
    pass "$1"
  else
    fail "$1 (expected '$2', got '$3')"
  fi
}

assert_contains() {
  local name="$1" needle="$2"
  if [[ "$OUT" == *"$needle"* ]]; then
    pass "$name"
  else
    fail "$name (output lacks '$needle')"
    printf '%s\n' "$OUT" | sed 's/^/    | /' >&2
  fi
}

assert_not_contains() {
  local name="$1" needle="$2"
  if [[ "$OUT" != *"$needle"* ]]; then
    pass "$name"
  else
    fail "$name (output unexpectedly contains '$needle')"
  fi
}

assert_file_mode() {
  local mode="$1" file="$2" actual
  case "$(uname -s)" in
    MINGW* | MSYS* | CYGWIN*)
      # NTFS does not honour chmod; Linux CI is the authority for file modes.
      if [ -e "$file" ]; then
        pass "mode $mode: $(basename "$file") (exists; modes are not enforced on Windows, verified in Linux CI)"
      else
        fail "mode $mode: missing $file"
      fi
      return
      ;;
  esac
  actual="$(stat -c '%a' "$file" 2>/dev/null || echo missing)"
  check_eq "mode $mode: $(basename "$file")" "$mode" "$actual"
}

assert_file_exists() {
  if [ -e "$2" ]; then pass "$1"; else fail "$1 (missing $2)"; fi
}

assert_file_absent() {
  if [ ! -e "$2" ]; then pass "$1"; else fail "$1 (unexpected $2)"; fi
}

# require_tools NAME... : fails loudly (never skips) when a tool is missing.
require_tools() {
  local missing=0 tool
  for tool in "$@"; do
    if ! command -v "$tool" >/dev/null 2>&1; then
      echo "FAIL: required tool missing: $tool" >&2
      missing=1
    fi
  done
  if [ "$missing" -ne 0 ]; then
    exit 3
  fi
}

finish() {
  echo "$PASSED passed, $FAILED failed"
  if [ "$FAILED" -gt 0 ]; then
    exit 1
  fi
}
