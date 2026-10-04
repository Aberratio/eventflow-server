#!/usr/bin/env bash
# Fails fast, naming only the MISSING settings, when a required GitHub
# Environment secret is empty. Never prints values.
# Usage: bash scripts/deploy/require-env.sh NAME...
set -Eeuo pipefail

if [ "$#" -eq 0 ]; then
  echo "Usage: require-env.sh NAME..." >&2
  exit 2
fi

missing=()
for name in "$@"; do
  if [ -z "${!name:-}" ]; then
    missing+=("$name")
  fi
done

if [ "${#missing[@]}" -gt 0 ]; then
  list=""
  for name in "${missing[@]}"; do
    list="${list:+$list, }$name"
  done
  env_name="${TARGET_ENV:-?}"
  echo "Missing required GitHub Environment secret(s) for '${env_name}': ${list}. Set them in Settings -> Environments -> ${env_name}. Nothing was deployed." >&2
  exit 30
fi

echo "All $# required settings are present."
