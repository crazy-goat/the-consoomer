<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

final class DsnParser
{
    /** @var list<string> */
    private static ?array $validExchangeTypes = null;

    /**
     * @param string $dsn DSN in format: amqp-consoomer://host/vhost/exchange?query=params
     * @return array{
     *     host: string,
     *     port: int,
     *     user: string,
     *     password: string,
     *     vhost: string,
     *     exchange: string,
     *     ssl?: bool,
     *     timeout?: float|int,
     *     read_timeout?: float|int,
     *     write_timeout?: float|int,
     *     connect_timeout?: float|int,
     *     exchange_type?: string,
     *     queue?: string,
     *     routing_key?: string,
     *     queues?: array<string, array{binding_keys?: list<string>}>,
     *     queue_arguments?: array<string, mixed>,
     *     max_unacked_messages?: int,
     *     auto_setup?: bool,
     *     retry?: bool,
     *     retry_count?: int,
     *     retry_delay?: int,
     *     retry_backoff?: bool,
     *     retry_max_delay?: int,
     *     retry_jitter?: bool,
     *     retry_circuit_breaker?: bool,
     *     retry_circuit_breaker_threshold?: int,
     *     retry_circuit_breaker_timeout?: int,
     *     retry_circuit_breaker_success_threshold?: int,
     *     heartbeat?: int,
     *     ssl_cert?: string,
     *     ssl_key?: string,
     *     ssl_cacert?: string,
     *     ssl_verify?: bool,
     *     exchange_flags?: int,
     *     queue_flags?: int,
     * }
     */
    public function parse(string $dsn): array
    {
        $info = parse_url($dsn);
        if ($info === false) {
            throw new \InvalidArgumentException('Malformed DSN: ' . $dsn);
        }
        $query = [];
        parse_str($info['query'] ?? '', $query);

        $pathOptions = $this->parsePath($info['path'] ?? '');

        $result = [
            'host' => $info['host'] ?? 'localhost',
            'port' => $info['port'] ?? 5672,
            'user' => urldecode($info['user'] ?? 'guest'),
            'password' => urldecode($info['pass'] ?? 'guest'),
            'vhost' => $pathOptions['vhost'],
            'exchange' => $pathOptions['exchange'],
        ];

        $scheme = $info['scheme'] ?? '';
        if ($scheme === 'amqps-consoomer') {
            $result['ssl'] = true;
            if (!isset($info['port'])) {
                $result['port'] = 5671;
            }
        } elseif ($scheme === 'amqps') {
            // @deprecated Legacy amqps:// scheme — no longer claimed by AmqpTransport::supports().
            // Only reachable when DsnParser is used independently of AmqpTransport.
            // Will be removed in 0.2. Use amqps-consoomer:// instead.
            $result['ssl'] = true;
            if (!isset($info['port'])) {
                $result['port'] = 5671;
            }
        }

        foreach ($query as $key => $value) {
            if (str_starts_with((string) $key, 'queue_arguments[')) {
                continue;
            }
            $result[$key] = $this->normalizeValue($value);
        }

        if (isset($query['queue_arguments']) && is_array($query['queue_arguments'])) {
            $result['queue_arguments'] = $this->normalizeQueueArguments($query['queue_arguments']);
        } else {
            $queueArgs = [];
            foreach ($query as $key => $value) {
                if (preg_match('/^queue_arguments\[(.+)\]$/', (string) $key, $matches)) {
                    $queueArgs[$matches[1]] = $this->normalizeValue($value);
                }
            }
            if ($queueArgs !== []) {
                $result['queue_arguments'] = $queueArgs;
            }
        }

        return $this->validateParsedOptions($result);
    }

    /**
     * Validates parsed DSN options.
     *
     * Checks for required exchange name and validates exchange_type if provided.
     *
     * @param array{
     *     host: string,
     *     port: int,
     *     user: string,
     *     password: string,
     *     vhost: string,
     *     exchange: string,
     *     ssl?: bool,
     *     exchange_type?: string,
     *     queue_arguments?: array<string, mixed>,
     * } $options
     * @return array{
     *     host: string,
     *     port: int,
     *     user: string,
     *     password: string,
     *     vhost: string,
     *     exchange: string,
     *     ssl?: bool,
     *     exchange_type?: string,
     *     queue_arguments?: array<string, mixed>,
     * }
     * @throws \InvalidArgumentException When exchange is missing or exchange_type is invalid
     */
    private function validateParsedOptions(array $options): array
    {
        if (empty($options['exchange'])) {
            throw new \InvalidArgumentException('DSN is missing required exchange name. Expected format: amqp-consoomer://host/vhost/exchange');
        }

        if (isset($options['exchange_type'])) {
            if (self::$validExchangeTypes === null) {
                self::$validExchangeTypes = array_map(
                    fn(\CrazyGoat\TheConsoomer\Enum\ExchangeType $type) => $type->value,
                    \CrazyGoat\TheConsoomer\Enum\ExchangeType::cases(),
                );
            }
            if (!in_array($options['exchange_type'], self::$validExchangeTypes, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid exchange_type "%s". Valid types are: %s',
                        $options['exchange_type'],
                        implode(', ', self::$validExchangeTypes),
                    ),
                );
            }
        }

        return $options;
    }

    /**
     * Parses DSN path to extract vhost and exchange.
     *
     * Note: Queue name must be provided as a query parameter (?queue=name),
     * not in the path. Path only contains vhost and exchange.
     *
     * @param string $path DSN path (e.g., /vhost/exchange)
     * @return array{vhost: string, exchange: string}
     */
    private function parsePath(string $path): array
    {
        $items = explode('/', trim($path, " \n\r\t\v\0/"));

        return [
            'vhost' => urldecode($items[0] ?? '/'),
            'exchange' => urldecode($items[1] ?? ''),
        ];
    }

    /**
     * Normalizes a value from string to appropriate type.
     *
     * Converts numeric strings to int/float and 'true'/'false' to booleans.
     *
     * @param mixed $value Value to normalize
     * @return mixed Normalized value (int, float, bool, or original string)
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        return $value;
    }

    /**
     * Normalizes queue arguments from query parameters.
     *
     * This method is public but only used internally by parse().
     * Kept public for backward compatibility - may be deprecated in future.
     *
     * @param array<string, mixed> $arguments Queue arguments
     * @return array<string, mixed> Normalized arguments
     */
    public function normalizeQueueArguments(array $arguments): array
    {
        $normalized = [];
        foreach ($arguments as $key => $value) {
            $normalized[$key] = $this->normalizeQueueArgumentValue($value);
        }
        return $normalized;
    }

    /**
     * Normalizes a single queue argument value.
     *
     * This method is redundant - it just delegates to normalizeValue().
     * Kept for potential future customization.
     *
     * @param mixed $value Value to normalize
     * @return mixed Normalized value
     */
    private function normalizeQueueArgumentValue(mixed $value): mixed
    {
        return $this->normalizeValue($value);
    }

    /**
     * @param array{
     *     host: string,
     *     port: int,
     *     user: string,
     *     password: string,
     *     vhost: string,
     *     exchange: string,
     *     ssl?: bool,
     *     exchange_type?: string,
     *     queue_arguments?: array<string, mixed>,
     * } $options
     * @deprecated This method is deprecated and will be removed in 0.2.
     *             Validation now happens automatically in parse().
     *             This method always returns true for backward compatibility.
     */
    public function validateOptions(array $options): bool
    {
        try {
            $this->validateParsedOptions($options);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
