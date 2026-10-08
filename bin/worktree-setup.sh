#!/usr/bin/env bash
# Prepare a fresh worktree: dependencies and the RabbitMQ test stack on free ports.
set -euo pipefail

# bin/worktree.sh writes .env.worktree. Other tools may create a worktree without it;
# then the defaults (the ports of the main checkout and CI) apply.
if [[ -f .env.worktree ]]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env.worktree
  set +a
fi

composer install --no-interaction --prefer-dist
composer rabbitmq-start
composer rabbitmq-wait
