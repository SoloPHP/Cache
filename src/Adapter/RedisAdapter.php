<?php

declare(strict_types=1);

namespace Solo\Cache\Adapter;

use DateInterval;
use Redis;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;

class RedisAdapter implements CacheAdapterInterface
{
    private const KEY_PATTERN = '/^[a-zA-Z0-9_.:-]+$/';
    private const KEY_PREFIX = 'cache:';

    // Error handling modes
    public const MODE_THROW = 0;  // Throw exceptions on errors
    public const MODE_FAIL = 1;   // Return false/default on errors

    private int $mode;

    public function __construct(
        private readonly Redis $redis,
        int $mode = self::MODE_THROW
    ) {
        $this->mode = $mode;

        if (!$this->redis->isConnected()) {
            if ($this->mode === self::MODE_THROW) {
                throw new CacheException('Redis connection is not established');
            }
        }

        // Configure Redis serialization if not set
        if ($this->redis->getOption(Redis::OPT_SERIALIZER) === Redis::SERIALIZER_NONE) {
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        }
    }

    /**
     * Set error handling mode at runtime
     */
    public function setMode(int $mode): void
    {
        $this->mode = $mode;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        try {
            $value = $this->redis->get($this->getPrefixedKey($key));

            if ($value === false) {
                return $default;
            }

            return $value; // Redis handles unserialization automatically
        } catch (\RedisException $e) {
            if ($this->mode === self::MODE_THROW) {
                throw new CacheException('Redis get operation failed: ' . $e->getMessage(), 0, $e);
            }
            return $default;
        }
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $prefixedKey = $this->getPrefixedKey($key);
        $ttlSeconds = $this->calculateTtlSeconds($ttl);

        try {
            if ($ttlSeconds === null) {
                return $this->redis->set($prefixedKey, $value); // Redis handles serialization
            }

            if ($ttlSeconds <= 0) {
                return $this->delete($key);
            }

            return $this->redis->setex($prefixedKey, $ttlSeconds, $value); // Redis handles serialization
        } catch (\RedisException $e) {
            if ($this->mode === self::MODE_THROW) {
                throw new CacheException('Redis set operation failed: ' . $e->getMessage(), 0, $e);
            }
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        /** @var int $result */
        $result = $this->redis->del($this->getPrefixedKey($key));

        return $result >= 0;
    }

    public function clear(): bool
    {
        $pattern = $this->getPrefixedKey('*');
        $keys = $this->redis->keys($pattern);

        if (empty($keys)) {
            return true;
        }

        /** @var int $result */
        $result = $this->redis->del($keys);

        return $result > 0;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // Convert to array and remove duplicates
        $keyArray = is_array($keys) ? array_values($keys) : iterator_to_array($keys);
        $keyArray = array_values(array_unique($keyArray));

        $this->validateKeys($keyArray);

        if (empty($keyArray)) {
            return [];
        }

        // Prepare prefixed keys for batch operation
        $prefixedKeys = array_map(fn($k) => $this->getPrefixedKey($k), $keyArray);

        try {
            // Single mGet call for all keys
            $rawValues = $this->redis->mGet($prefixedKeys);

            // Build result array (Redis handles unserialization)
            $result = [];
            foreach ($keyArray as $index => $key) {
                $value = $rawValues[$index];
                $result[$key] = $value === false ? $default : $value;
            }
        } catch (\RedisException $e) {
            if ($this->mode === self::MODE_THROW) {
                throw new CacheException('Redis mGet operation failed: ' . $e->getMessage(), 0, $e);
            }
            // Return defaults for all keys on error
            return array_fill_keys($keyArray, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $valueArray = is_array($values) ? $values : iterator_to_array($values);

        if (empty($valueArray)) {
            return true;
        }

        $ttlSeconds = $this->calculateTtlSeconds($ttl);

        // No TTL: use mSet for atomic batch operation
        if ($ttlSeconds === null) {
            $prefixedValues = [];
            foreach ($valueArray as $key => $value) {
                if (!is_string($key)) {
                    throw new InvalidArgumentException('Cache key must be a string');
                }
                $this->validateKey($key);
                $prefixedValues[$this->getPrefixedKey($key)] = $value; // Redis handles serialization
            }

            try {
                return $this->redis->mSet($prefixedValues);
            } catch (\RedisException $e) {
                if ($this->mode === self::MODE_THROW) {
                    throw new CacheException('Redis mSet operation failed: ' . $e->getMessage(), 0, $e);
                }
                return false;
            }
        }

        // With TTL: must use individual setex calls (Redis limitation)
        if ($ttlSeconds <= 0) {
            return $this->deleteMultiple(array_keys($valueArray));
        }

        $success = true;
        foreach ($valueArray as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string');
            }
            $success = $this->set($key, $value, $ttlSeconds) && $success;
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        // Convert to array and remove duplicates
        $keyArray = is_array($keys) ? array_values($keys) : iterator_to_array($keys);
        $keyArray = array_unique($keyArray);

        $this->validateKeys($keyArray);

        if (empty($keyArray)) {
            return true;
        }

        // Prepare prefixed keys for batch operation
        $prefixedKeys = array_map(fn($k) => $this->getPrefixedKey($k), $keyArray);

        try {
            // Single del call with array of keys
            /** @var int $result */
            $result = $this->redis->del($prefixedKeys);

            // Redis returns number of deleted keys
            return $result > 0;
        } catch (\RedisException $e) {
            if ($this->mode === self::MODE_THROW) {
                throw new CacheException('Redis del operation failed: ' . $e->getMessage(), 0, $e);
            }
            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        /** @var int|bool $result */
        $result = $this->redis->exists($this->getPrefixedKey($key));

        return is_int($result) && $result > 0;
    }

    private function getPrefixedKey(string $key): string
    {
        return self::KEY_PREFIX . $key;
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }

        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException(
                'Cache key contains invalid characters. ' .
                'Only alphanumeric characters, underscores, dots, colons, and hyphens are allowed.'
            );
        }
    }

    /**
     * @param iterable<mixed> $keys
     */
    private function validateKeys(iterable $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string');
            }

            $this->validateKey($key);
        }
    }

    private function calculateTtlSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new \DateTimeImmutable();
            $expiresAt = $now->add($ttl);
            return $expiresAt->getTimestamp() - time();
        }

        return $ttl;
    }
}
