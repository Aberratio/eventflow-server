#!/usr/bin/env bash
# Validates deploy/rollback workflow inputs before any job that can read
# environment secrets is allowed to start.
#
# All input comes from the environment, so no workflow input is ever
# interpolated into script text. Secrets are never printed; the confirmation
# token is never echoed either.
set -Eeuo pipefail

op="${GUARD_OPERATION:-}"              # deploy | rollback
event="${GUARD_EVENT:-}"               # github.event_name
target="${GUARD_TARGET:-}"             # dev | staging | production
confirm="${GUARD_CONFIRM:-}"           # exact token, no trimming
ref="${GUARD_REF:-}"                   # github.ref
prod_ref="${GUARD_PRODUCTION_REF:-}"   # refs/heads/master (server) | refs/heads/main (client)
skip_tests="${GUARD_SKIP_TESTS:-false}"        # client only; 'true' | 'false'
migration_mode="${GUARD_MIGRATION_MODE:-}"     # server only; run | skip | ''
snapshot="${GUARD_SNAPSHOT:-}"                 # rollback only

refuse() {
  local code="$1"
  shift
  echo "Guard refused: $*" >&2
  exit "$code"
}

case "$op" in
  deploy | rollback) ;;
  *) refuse 60 "GUARD_OPERATION must be deploy or rollback." ;;
esac

case "$prod_ref" in
  refs/heads/?*) ;;
  *) refuse 60 "GUARD_PRODUCTION_REF must be a branch ref." ;;
esac

case "$target" in
  dev | staging | production) ;;
  *) refuse 60 "Deploy target must be dev, staging or production." ;;
esac

case "$event" in
  push | workflow_dispatch) ;;
  *) refuse 61 "Unsupported trigger: ${event:-<empty>}." ;;
esac

if [ "$event" = "push" ] && [ "$target" != "staging" ]; then
  refuse 61 "A push may only deploy to staging."
fi

if [ "$op" = "rollback" ] && [ "$event" != "workflow_dispatch" ]; then
  refuse 61 "Rollback runs only from workflow_dispatch."
fi

if [ "$target" = "production" ] && [ "$event" != "workflow_dispatch" ]; then
  refuse 61 "Production runs only from workflow_dispatch."
fi

if [ "$target" = "production" ]; then
  if [ "$op" = "rollback" ]; then
    token="ROLLBACK_PRODUCTION"
  else
    token="DEPLOY_PRODUCTION"
  fi
  if [ "$confirm" != "$token" ]; then
    refuse 62 "Production $op requires confirm_production=$token."
  fi

  if [ "$ref" != "$prod_ref" ]; then
    # Releases deploy from their vYYYY.MM.PATCH tag (RELEASING.md).
    if [ "$op" = "deploy" ] && { [[ "$ref" == refs/tags/rollback-?* ]] || [[ "$ref" =~ ^refs/tags/v[0-9]{4}\.[0-9]{1,2}\.[0-9]+$ ]]; }; then
      :
    else
      refuse 63 "Production $op must run from $prod_ref (or, for deploy, a vYYYY.MM.PATCH or refs/tags/rollback-* tag); got ${ref:-<empty>}."
    fi
  fi
fi

case "$skip_tests" in
  true | false) ;;
  *) refuse 64 "skipTests must be true or false." ;;
esac

if [ "$target" = "production" ] && [ "$skip_tests" = "true" ]; then
  refuse 64 "Tests cannot be skipped for a production deploy."
fi

if [ "$op" = "deploy" ]; then
  case "$migration_mode" in
    "" | run | skip) ;;
    *) refuse 65 "migration_mode must be run or skip." ;;
  esac
fi

if [ "$op" = "rollback" ]; then
  if ! [[ "$snapshot" =~ ^(latest|list|code-[0-9]{8}-[0-9]{6}-[A-Za-z0-9._-]{1,80}\.tar\.gz)$ ]]; then
    refuse 66 "snapshot must be latest, list or a code-YYYYmmdd-HHMMSS-<id>.tar.gz name."
  fi
fi

echo "Guard passed: operation=$op target=$target event=$event ref=$ref"
