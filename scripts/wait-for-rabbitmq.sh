#!/bin/bash

set -e

HOST="${RABBITMQ_HOST:-localhost}"
PORT="${RABBITMQ_PORT:-5672}"
MAX_RETRIES=30
RETRY_INTERVAL=2

echo "Waiting for RabbitMQ at $HOST:$PORT..."

for i in $(seq 1 $MAX_RETRIES); do
    if nc -z "$HOST" "$PORT" 2>/dev/null; then
        echo "RabbitMQ is ready!"
        exit 0
    fi
    
    echo "Attempt $i/$MAX_RETRIES - RabbitMQ not ready yet..."
    sleep $RETRY_INTERVAL
done

echo "ERROR: RabbitMQ did not become ready in time"
exit 1
