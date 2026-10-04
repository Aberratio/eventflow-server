#!/usr/bin/env bash
# Writes ~/.ssh/deploy_key and ~/.ssh/known_hosts for the deploy/rollback jobs.
# Input (environment only): SSH_HOST SSH_PORT SSH_USER SSH_PRIVATE_KEY SSH_KNOWN_HOSTS
# Callers use: ssh -i ~/.ssh/deploy_key -p "$SSH_PORT" -o BatchMode=yes "$SSH_USER@$SSH_HOST"
# Never prints secret values.
set -Eeuo pipefail

if [ -z "${SSH_HOST:-}" ] || [ -z "${SSH_PORT:-}" ] || [ -z "${SSH_USER:-}" ] || [ -z "${SSH_PRIVATE_KEY:-}" ] || [ -z "${SSH_KNOWN_HOSTS:-}" ]; then
  echo "SSH_HOST, SSH_PORT, SSH_USER, SSH_PRIVATE_KEY and SSH_KNOWN_HOSTS are required." >&2
  exit 30
fi

case "$SSH_HOST" in
  http://* | https://* | */*)
    echo "SSH_HOST must be a hostname only, for example h29.seohost.pl." >&2
    exit 31
    ;;
esac

case "$SSH_PORT" in
  '' | *[!0-9]*)
    echo "SSH_PORT must be a number." >&2
    exit 32
    ;;
esac

install -m 700 -d ~/.ssh
printf '%s\n' "$SSH_PRIVATE_KEY" > ~/.ssh/deploy_key
chmod 600 ~/.ssh/deploy_key
printf '%s\n' "$SSH_KNOWN_HOSTS" > ~/.ssh/known_hosts
chmod 600 ~/.ssh/known_hosts
if [ ! -s ~/.ssh/known_hosts ]; then
  echo "SSH_KNOWN_HOSTS is empty." >&2
  exit 34
fi
