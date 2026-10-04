#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/../.."
# shellcheck source=tests/ops/lib.sh
source tests/ops/lib.sh
require_tools bash

NAMES=()
req() { env -i PATH="$PATH" "$@" bash scripts/deploy/require-env.sh "${NAMES[@]}"; }

NAMES=(SSH_HOST SSH_PORT API_URL)
assert_exit 0 req TARGET_ENV=staging SSH_HOST=h SSH_PORT=22 API_URL=https://a
assert_contains "success message" "All 3 required settings are present."

assert_exit 30 req TARGET_ENV=staging SSH_HOST=s3cr3t-host SSH_PORT=
assert_contains "names SSH_PORT and API_URL" "SSH_PORT, API_URL"
assert_contains "names the environment" "'staging'"
assert_contains "says nothing was deployed" "Nothing was deployed."
assert_not_contains "no secret values leak" "s3cr3t-host"

assert_exit 30 req SSH_HOST=x SSH_PORT=1
assert_contains "unset TARGET_ENV is tolerated" "'?'"
assert_not_contains "present names are not listed as missing" "SSH_HOST,"

assert_exit 30 req TARGET_ENV=production
assert_contains "all three missing" "SSH_HOST, SSH_PORT, API_URL"

NAMES=()
assert_exit 2 req TARGET_ENV=staging

finish
