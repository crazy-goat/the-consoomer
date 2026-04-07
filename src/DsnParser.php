<?php

declare(strict_types=1);

namespace CrazyGoat\TheConsoomer;

class DsnParser
{
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
            'user' => $info['user'] ?? 'guest',
            'password' => $info['pass'] ?? 'guest',
            'vhost' => $pathOptions['vhost'],
            'exchange' => $pathOptions['exchange'],
        ];

        $scheme = $info['scheme'] ?? '';
        if (($scheme ?? '') === 'amqps-consoomer') {
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

        return $result;
    }

    private function parsePath(string $path): array
    {
        $items = explode('/', trim($path, " \n\r\t\v\0/"));

        return [
            'vhost' => urldecode($items[0] ?? '/'),
            'exchange' => urldecode($items[1] ?? ''),
        ];
    }

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

    public function normalizeQueueArguments(array $arguments): array
    {
        $normalized = [];
        foreach ($arguments as $key => $value) {
            $normalized[$key] = $this->normalizeQueueArgumentValue($value);
        }
        return $normalized;
    }

    private function normalizeQueueArgumentValue(mixed $value): mixed
    {
        return $this->normalizeValue($value);
    }

    public function validateOptions(array $options): bool
    {
        if (empty($options['exchange'])) {
            return false;
        }

        if (isset($options['exchange_type'])) {
            $validTypes = array_map(
                fn(\CrazyGoat\TheConsoomer\Enum\ExchangeType $type) => $type->value,
                \CrazyGoat\TheConsoomer\Enum\ExchangeType::cases(),
            );
            if (!in_array($options['exchange_type'], $validTypes, true)) {
                return false;
            }
        }

        return true;
    }
}
