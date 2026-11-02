<?php

declare(strict_types=1);

namespace Solo\Cache\Adapter;

use DateInterval;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Internal\Key;
use Solo\Cache\Internal\PhpSerializer;
use Solo\Cache\Internal\Ttl;
use Throwable;

abstract class AbstractAdapter implements CacheAdapterInterface
{
    public function __construct(protected ErrorMode $mode = ErrorMode::Throw)
    {
    }

    public function setMode(ErrorMode $mode): void
    {
        $this->mode = $mode;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach (Key::keys($keys) as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<int|string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $values = Key::values($values);
        $seconds = Ttl::seconds($ttl);
        $success = true;

        foreach ($values as $key => $value) {
            $success = $this->set((string) $key, $value, $seconds) && $success;
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach (Key::keys($keys) as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }

    /** Serializes a value, reporting a value that cannot be stored as a storage failure. */
    protected function encode(mixed $value): string|false
    {
        try {
            return PhpSerializer::encode($value);
        } catch (CacheException $exception) {
            return $this->fail($exception->getMessage(), $exception->getPrevious());
        }
    }

    protected function fail(string $message, ?Throwable $previous = null): false
    {
        if ($this->mode === ErrorMode::Throw) {
            throw new CacheException($message, 0, $previous);
        }

        return false;
    }
}
