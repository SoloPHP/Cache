<?php

declare(strict_types=1);

namespace Solo\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Solo\Cache\Adapter\CacheAdapterInterface;

class Cache implements CacheInterface
{
    public function __construct(
        private readonly CacheAdapterInterface $adapter
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->adapter->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->adapter->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->adapter->delete($key);
    }

    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->adapter->getMultiple($keys, $default);
    }

    /**
     * @param iterable<int|string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return $this->adapter->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->adapter->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->adapter->has($key);
    }
}
