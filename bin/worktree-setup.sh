#!/usr/bin/env bash
# Prepare a fresh worktree: dependencies and the RabbitMQ test stack on free ports.
set -euo pipefail

set -a
# shellcheck disable=SC1091
. ./.env.worktree
set +a

composer install --no-interaction --prefer-dist
composer rabbitmq-start
composer rabbitmq-wait
