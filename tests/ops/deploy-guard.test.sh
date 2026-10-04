#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/../.."
# shellcheck source=tests/ops/lib.sh
source tests/ops/lib.sh
require_tools bash

# guard OPERATION EVENT TARGET CONFIRM REF [SKIP] [MIGRATION] [SNAPSHOT] [PRODUCTION_REF]
guard() {
  env -i PATH="$PATH" \
    GUARD_OPERATION="$1" GUARD_EVENT="$2" GUARD_TARGET="$3" GUARD_CONFIRM="$4" \
    GUARD_REF="$5" GUARD_SKIP_TESTS="${6:-false}" GUARD_MIGRATION_MODE="${7:-}" \
    GUARD_SNAPSHOT="${8:-}" GUARD_PRODUCTION_REF="${9-refs/heads/master}" \
    bash scripts/ci/deploy-guard.sh
}

M=refs/heads/master
D=workflow_dispatch

# Success paths
assert_exit 0 guard deploy push staging '' $M
assert_exit 0 guard deploy push staging '' refs/heads/feature
assert_exit 0 guard deploy $D staging '' $M
assert_exit 0 guard deploy $D staging '' $M false run
assert_exit 0 guard deploy $D staging '' $M false skip
assert_exit 0 guard deploy $D production DEPLOY_PRODUCTION $M
assert_exit 0 guard deploy $D production DEPLOY_PRODUCTION $M false run
assert_exit 0 guard deploy $D production DEPLOY_PRODUCTION refs/tags/rollback-x
assert_exit 0 guard deploy $D production DEPLOY_PRODUCTION refs/tags/rollback-2026-10-05
assert_exit 0 guard rollback $D production ROLLBACK_PRODUCTION $M false '' latest
assert_exit 0 guard rollback $D staging '' $M false '' list
assert_exit 0 guard rollback $D staging '' $M false '' code-20261005-101500-staging-12-abc1234.tar.gz
assert_exit 0 guard deploy $D production DEPLOY_PRODUCTION refs/heads/main false '' '' refs/heads/main
# Client mode: skipTests
assert_exit 0 guard deploy $D staging '' refs/heads/main true '' '' refs/heads/main
assert_exit 64 guard deploy $D production DEPLOY_PRODUCTION refs/heads/main true '' '' refs/heads/main
assert_exit 64 guard deploy $D production DEPLOY_PRODUCTION $M true
assert_exit 64 guard deploy $D staging '' $M maybe

# 60: malformed operation / target / production ref
assert_exit 60 guard bogus $D staging '' $M
assert_exit 60 guard '' $D staging '' $M
assert_exit 60 guard deploy $D prod DEPLOY_PRODUCTION $M
assert_exit 60 guard deploy $D '' '' $M
assert_exit 60 guard deploy $D staging '' $M false '' '' ''
assert_exit 60 guard deploy $D staging '' $M false '' '' refs/tags/x

# 61: trigger rules
assert_exit 61 guard deploy pull_request staging '' $M
assert_exit 61 guard deploy schedule staging '' $M
assert_exit 61 guard deploy '' staging '' $M
assert_exit 61 guard deploy push production DEPLOY_PRODUCTION $M
assert_exit 61 guard rollback push staging '' $M false '' list

# 62: production confirmation is exact
assert_exit 62 guard deploy $D production '' $M
assert_exit 62 guard deploy $D production 'DEPLOY_PRODUCTION ' $M
assert_exit 62 guard deploy $D production ' DEPLOY_PRODUCTION' $M
assert_exit 62 guard deploy $D production deploy_production $M
assert_exit 62 guard deploy $D production ROLLBACK_PRODUCTION $M
assert_exit 62 guard rollback $D production DEPLOY_PRODUCTION $M false '' latest
assert_exit 62 guard rollback $D production '' $M false '' latest

# 63: production ref
assert_exit 63 guard deploy $D production DEPLOY_PRODUCTION refs/heads/feat
assert_exit 63 guard deploy $D production DEPLOY_PRODUCTION refs/tags/v1
assert_exit 63 guard deploy $D production DEPLOY_PRODUCTION refs/tags/rollback-
assert_exit 63 guard deploy $D production DEPLOY_PRODUCTION refs/heads/rollback-x
assert_exit 63 guard deploy $D production DEPLOY_PRODUCTION ''
assert_exit 63 guard rollback $D production ROLLBACK_PRODUCTION refs/tags/rollback-x false '' latest
assert_exit 63 guard rollback $D production ROLLBACK_PRODUCTION refs/heads/feat false '' latest

# 65: migration mode
assert_exit 65 guard deploy $D staging '' $M false bogus
assert_exit 65 guard deploy $D production DEPLOY_PRODUCTION $M false RUN

# 66: snapshot name
assert_exit 66 guard rollback $D staging '' $M false '' '../x'
assert_exit 66 guard rollback $D staging '' $M false '' ''
assert_exit 66 guard rollback $D staging '' $M false '' 'LATEST'
assert_exit 66 guard rollback $D staging '' $M false '' 'code-20261005-101500-x/../y.tar.gz'
assert_exit 66 guard rollback $D staging '' $M false '' 'prerollback-20261005-101500-x.tar.gz'
assert_exit 66 guard rollback $D staging '' $M false '' 'code-20261005-101500-x.tar.gz; id'

# Messages: success line and no leaking of the confirmation token
assert_exit 0 guard deploy $D production DEPLOY_PRODUCTION $M
assert_contains "success summary line" "Guard passed: operation=deploy target=production event=workflow_dispatch ref=refs/heads/master"
assert_exit 62 guard deploy $D production 'WRONG-TOKEN-VALUE' $M
assert_not_contains "wrong token is not echoed" "WRONG-TOKEN-VALUE"
assert_contains "names the expected token" "confirm_production=DEPLOY_PRODUCTION"

finish
