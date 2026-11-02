<?php

declare(strict_types=1);

namespace Solo\Cache\Adapter;

use DateInterval;
use Redis;
use RedisException;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;
use Solo\Cache\Internal\Key;
use Solo\Cache\Internal\PhpSerializer;
use Solo\Cache\Internal\Ttl;

class RedisAdapter extends AbstractAdapter
{
    /** About 285,000 years: far below the point where Redis rejects an expiration as overflowing. */
    private const int MAX_TTL = 9_000_000_000_000;

    private readonly string $prefix;

    /** Once connected, phpredis reconnects on the next command after a dropped connection. */
    private bool $established = false;

    public function __construct(
        private readonly Redis $redis,
        ErrorMode $mode = ErrorMode::Throw,
        string $prefix = 'cache:'
    ) {
        parent::__construct($mode);

        if ($prefix === '') {
            throw new InvalidArgumentException('Redis cache prefix cannot be empty');
        }

        $this->prefix = str_ends_with($prefix, ':') ? $prefix : $prefix . ':';

        $this->established = $redis->isConnected();
        if (!$this->established) {
            $this->fail('Redis connection is not established');
            return;
        }

        $this->assertClientConfiguration();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        Key::validate($key);

        $value = $this->run('get', function () use ($key): string|false {
            $value = $this->redis->get($this->prefix . $key);
            if ($value !== false && !is_string($value)) {
                throw new CacheException('Unexpected Redis GET response');
            }

            return $value;
        }, false);

        // Values are encoded and decoded outside run(): their hooks may use this client.
        return $value === false ? $default : PhpSerializer::decode($value, $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        Key::validate($key);
        $seconds = $this->seconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $serialized = $this->encode($value);
        if ($serialized === false) {
            return false;
        }

        return $this->run('set', function () use ($key, $serialized, $seconds): bool {
            $result = $seconds === null
                ? $this->redis->set($this->prefix . $key, $serialized)
                : $this->redis->setex($this->prefix . $key, $seconds, $serialized);

            return $this->requireWriteSuccess($result);
        }, false);
    }

    public function delete(string $key): bool
    {
        Key::validate($key);

        return $this->run('delete', fn(): bool => $this->requireDeleteSuccess(
            $this->redis->del($this->prefix . $key)
        ), false);
    }

    public function clear(): bool
    {
        return $this->run('clear', function (): bool {
            $pattern = addcslashes($this->prefix, '\\*?[]') . '*';
            $iterator = null;

            do {
                $keys = $this->redis->scan($iterator, $pattern, 1000);
                $this->checkLastError();

                if (!is_int($iterator) && !is_string($iterator)) {
                    throw new CacheException('Redis SCAN did not return a cursor');
                }

                $owned = [];
                foreach ($keys ?: [] as $key) {
                    if (
                        str_starts_with($key, $this->prefix)
                        && Key::isValid(substr($key, strlen($this->prefix)))
                    ) {
                        $owned[] = $key;
                    }
                }

                if ($owned !== []) {
                    $this->requireDeleteSuccess($this->redis->del($owned));
                }
            } while ((string) $iterator !== '0');

            return true;
        }, false);
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = Key::keys($keys);

        if ($keys === []) {
            return [];
        }

        $rawValues = $this->run('getMultiple', function () use ($keys): array {
            $rawValues = $this->redis->mGet($this->prefixed($keys));

            if (!is_array($rawValues) || count($rawValues) !== count($keys)) {
                throw new CacheException('Unexpected Redis MGET response');
            }

            $rawValues = array_values($rawValues);
            foreach ($rawValues as $value) {
                if ($value !== false && !is_string($value)) {
                    throw new CacheException('Unexpected Redis MGET item');
                }
            }

            /** @var list<string|false> $rawValues */
            return $rawValues;
        }, array_fill(0, count($keys), false));

        $values = [];
        foreach ($keys as $index => $key) {
            $value = $rawValues[$index];
            $values[$key] = is_string($value) ? PhpSerializer::decode($value, $default) : $default;
        }

        return $values;
    }

    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $values = Key::values($values);

        if ($values === []) {
            return true;
        }

        $seconds = $this->seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->deleteMultiple(array_map(strval(...), array_keys($values)));
        }

        $serialized = [];
        foreach ($values as $key => $value) {
            $payload = $this->encode($value);
            if ($payload === false) {
                return false;
            }
            $serialized[$this->prefix . $key] = $payload;
        }

        return $this->run('setMultiple', function () use ($serialized, $seconds): bool {
            if ($seconds === null) {
                return $this->requireWriteSuccess($this->redis->mSet($serialized));
            }

            $this->redis->pipeline();
            try {
                foreach ($serialized as $key => $value) {
                    $this->redis->setex((string) $key, $seconds, $value);
                }
                $results = $this->redis->exec();
            } finally {
                // Never leave the client queuing commands after a failure.
                if ($this->redis->getMode() === Redis::PIPELINE) {
                    $this->redis->discard();
                }
            }

            $this->checkLastError();
            if (!is_array($results) || count($results) !== count($serialized)) {
                throw new CacheException('Unexpected Redis pipeline response');
            }

            foreach ($results as $result) {
                $this->requireWriteSuccess($result);
            }

            return true;
        }, false);
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = Key::keys($keys);

        if ($keys === []) {
            return true;
        }

        return $this->run(
            'deleteMultiple',
            fn(): bool => $this->requireDeleteSuccess($this->redis->del($this->prefixed($keys))),
            false
        );
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function prefixed(array $keys): array
    {
        return array_map(fn(string $key): string => $this->prefix . $key, $keys);
    }

    /** Seconds until expiry, capped below the point where Redis rejects it as overflowing. */
    private function seconds(null|int|DateInterval $ttl): ?int
    {
        $seconds = Ttl::seconds($ttl);

        return $seconds === null ? null : min($seconds, self::MAX_TTL);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param T $fallback
     * @return T
     */
    private function run(string $operation, callable $callback, mixed $fallback): mixed
    {
        try {
            // A timeout marks the client disconnected, and only a command makes it reconnect.
            $this->established = $this->established || $this->redis->isConnected();
            if (!$this->established) {
                throw new CacheException('Redis connection is not established');
            }

            $this->assertClientConfiguration();
            $this->redis->clearLastError();
            $result = $callback();
            $this->checkLastError();

            return $result;
        } catch (RedisException | CacheException $exception) {
            $this->fail('Redis ' . $operation . ' operation failed: ' . $exception->getMessage(), $exception);

            return $fallback;
        }
    }

    private function assertClientConfiguration(): void
    {
        $prefix = $this->redis->getOption(Redis::OPT_PREFIX);

        if ($prefix !== false && $prefix !== null && $prefix !== '') {
            throw new InvalidArgumentException('Use the adapter prefix instead of Redis::OPT_PREFIX');
        }

        if ($this->redis->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE) {
            throw new InvalidArgumentException('Redis::OPT_SERIALIZER must be Redis::SERIALIZER_NONE');
        }

        if ($this->redis->getOption(Redis::OPT_COMPRESSION) !== Redis::COMPRESSION_NONE) {
            throw new InvalidArgumentException('Redis::OPT_COMPRESSION must be Redis::COMPRESSION_NONE');
        }

        if ($this->redis->getMode() !== Redis::ATOMIC) {
            throw new InvalidArgumentException('Redis cache requires a client outside transactions and pipelines');
        }
    }

    private function requireWriteSuccess(mixed $result): true
    {
        if ($result !== true && $result !== 'OK') {
            throw new CacheException('Redis write returned an unsuccessful response');
        }

        return true;
    }

    private function requireDeleteSuccess(mixed $result): true
    {
        if (!is_int($result) || $result < 0) {
            throw new CacheException('Redis delete returned an unsuccessful response');
        }

        return true;
    }

    private function checkLastError(): void
    {
        $error = $this->redis->getLastError();

        if ($error !== null) {
            throw new CacheException($error);
        }
    }
}
