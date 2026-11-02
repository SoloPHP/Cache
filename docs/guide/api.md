# Public API reference

This reference describes Solo Cache 2.0: PHP 8.3+, the `Solo\Cache` namespace and one runtime dependency, `psr/simple-cache` 3. [Getting started](index.md) walks through the setup, and [Behavior in detail](details.md) explains the rules below. Exception messages are not part of the compatibility promise, and classes under `Solo\Cache\Internal` are not part of the public API.

| Type | Role |
|---|---|
| `Adapter\FileAdapter` | PSR-16 cache with one file per entry |
| `Adapter\RedisAdapter` | PSR-16 cache under a key prefix on a `Redis` client |
| `Adapter\ErrorMode` | Throw or fall back when storage fails |
| `Adapter\AbstractAdapter` | Base class for adapters: error mode and batch methods |
| `Adapter\CacheAdapterInterface` | Marker interface extending `Psr\SimpleCache\CacheInterface` |
| `Cache` | Readonly wrapper around any PSR-16 implementation |
| `Exception\CacheException` | Storage failures |
| `Exception\InvalidArgumentException` | Invalid keys, TTLs and configuration |

## Shared contract

Both adapters implement every method of `Psr\SimpleCache\CacheInterface` with the same rules:

- **Keys** are non-empty strings of ASCII letters, digits, `_`, `.` and `-`. Anything else, including a trailing newline, raises `InvalidArgumentException`. See [Keys](details.md#keys).
- **Values** are stored with PHP serialization. A value that cannot be serialized without loss, such as a closure or a resource, is a storage failure. See [Values](details.md#values-and-serialization).
- **TTL** is `null` for no expiration, an integer number of seconds or a `DateInterval`. A TTL of zero or less deletes the entry. An interval PHP cannot convert raises `InvalidArgumentException`.
- **Failures** follow the [error mode](#errormode). Invalid arguments and configuration throw in both modes.

| Method | Returns |
|---|---|
| `get(string $key, mixed $default = null): mixed` | The stored value, or `$default` for a miss, an expired entry or an unreadable payload |
| `set(string $key, mixed $value, null\|int\|DateInterval $ttl = null): bool` | `true` once stored, or deleted for a TTL of zero or less |
| `delete(string $key): bool` | `true` when the entry is gone, including when it never existed |
| `clear(): bool` | `true` when every entry of this pool is gone |
| `getMultiple(iterable $keys, mixed $default = null): iterable` | An array of key ⇒ value in request order, duplicates collapsed |
| `setMultiple(iterable $values, null\|int\|DateInterval $ttl = null): bool` | `true` when every entry was stored |
| `deleteMultiple(iterable $keys): bool` | `true` when every entry is gone |
| `has(string $key): bool` | `true` exactly when `get()` would return a stored value, `null` and `false` included |

Batch methods accept arrays and `Traversable` values. `getMultiple()` and `deleteMultiple()` take lists of string keys. `setMultiple()` takes a map of key ⇒ value, where an integer index stands for its decimal string, so `[123 => $value]` and `['123' => $value]` both write the key `'123'`. All keys are validated before anything is written or deleted. The array that `getMultiple()` returns follows PHP's rules for array keys, so a numeric key such as `'123'` comes back as the integer index `123`.

## FileAdapter

`Solo\Cache\Adapter\FileAdapter` extends `AbstractAdapter`.

```php
public function __construct(string $cacheDirectory, ErrorMode $mode = ErrorMode::Throw)
```

Creates `$cacheDirectory` with mode `0700` when it is missing, including parent directories, and resolves it to a real path. The constructor throws `CacheException` in both error modes when the path is empty or contains a null byte, cannot be created, or is not writable.

### gc()

```php
public function gc(): int
```

Removes the pool's expired and corrupt entries and the temporary files of writers that crashed, and returns how many files it removed. It reads only entry headers and never restores a value, so no class has to be loadable and no hook runs. An entry that another process rewrote while `gc()` was checking it is kept. In `ErrorMode::Fail`, files that cannot be read or locked are skipped. [Garbage collection](details.md#garbage-collection) describes each step.

| Method | Storage it touches |
|---|---|
| `get()`, `has()`, `getMultiple()` | Reads the entry file; never takes the lock or deletes |
| `set()`, `setMultiple()` | Writes a temporary file and renames it over the entry under the pool lock, one entry at a time |
| `delete()`, `deleteMultiple()` | Unlinks the entry file under the pool lock |
| `clear()` | Unlinks every entry and temporary file of the pool under the lock |

## RedisAdapter

`Solo\Cache\Adapter\RedisAdapter` extends `AbstractAdapter`.

```php
public function __construct(Redis $redis, ErrorMode $mode = ErrorMode::Throw, string $prefix = 'cache:')
```

Stores each item under `$prefix . $key`. A prefix without a trailing `:` gains one, and an empty prefix raises `InvalidArgumentException`. A client that is not connected raises `CacheException` in `ErrorMode::Throw`; in `ErrorMode::Fail` the adapter is created and its operations fall back until the client connects.

The client must have `Redis::OPT_PREFIX` unset, `Redis::OPT_SERIALIZER` set to `Redis::SERIALIZER_NONE`, `Redis::OPT_COMPRESSION` set to `Redis::COMPRESSION_NONE`, and must not be inside `multi()` or `pipeline()`. The adapter checks this at construction and before every operation, never changes the options itself, and raises `InvalidArgumentException` in both error modes otherwise.

| Method | Redis commands |
|---|---|
| `get()`, `has()` | `GET` |
| `set()` | `SET`, or `SETEX` with a TTL |
| `delete()` | `DEL` |
| `clear()` | `SCAN` over the prefix with `COUNT 1000`, then `DEL` for each batch |
| `getMultiple()` | One `MGET` |
| `setMultiple()` | One `MSET`, or one pipeline of `SETEX` commands with a TTL |
| `deleteMultiple()` | One `DEL` |

A TTL longer than 9,000,000,000,000 seconds (about 285,000 years) is shortened to that value, because Redis rejects expirations that overflow its millisecond clock. [Redis adapter](details.md#redis-adapter) covers namespaces, partial writes and reconnection.

## ErrorMode

```php
enum ErrorMode
{
    case Throw;
    case Fail;
}
```

| Case | On a storage failure |
|---|---|
| `ErrorMode::Throw` | Raises `CacheException`, with the underlying exception, if any, as the previous one |
| `ErrorMode::Fail` | `get()` and `getMultiple()` return the default for each key; `has()` returns `false`; every other method returns `false` |

`ErrorMode::Throw` is the default for both adapters. Invalid keys, TTLs and configuration raise `InvalidArgumentException` in either mode.

## AbstractAdapter

`Solo\Cache\Adapter\AbstractAdapter` is an abstract class implementing `CacheAdapterInterface`. Extend it to write another adapter: implement `get()`, `set()`, `delete()` and `clear()`, and it supplies the rest.

```php
public function __construct(ErrorMode $mode = ErrorMode::Throw)
public function setMode(ErrorMode $mode): void
public function has(string $key): bool
public function getMultiple(iterable $keys, mixed $default = null): iterable
public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
public function deleteMultiple(iterable $keys): bool
protected function fail(string $message, ?Throwable $previous = null): false
```

- `has()` calls `get()` with a private sentinel default, so it agrees with `get()` for every value.
- The batch methods validate all keys, convert the TTL once, and then call `get()`, `set()` or `delete()` for each key. A failed key does not stop the remaining ones in `ErrorMode::Fail`; the result is `false` if any key failed.
- `fail()` raises `CacheException` in `ErrorMode::Throw` and returns `false` otherwise. Call it on every storage failure: `return $this->fail('Disk full', $exception);`.

## Cache

```php
readonly class Cache implements CacheInterface
{
    public function __construct(CacheInterface $adapter)
}
```

Forwards each PSR-16 method to `$adapter` unchanged. Because the class is readonly, a subclass must be declared `readonly` as well.

## CacheAdapterInterface

`Solo\Cache\Adapter\CacheAdapterInterface` extends `Psr\SimpleCache\CacheInterface` without adding methods. It marks the package's adapters and lets custom adapters written for 1.x keep their `implements` clause.

## Exceptions

Both classes implement the matching PSR-16 interface, so callers can catch `Psr\SimpleCache\CacheException` and `Psr\SimpleCache\InvalidArgumentException`.

| Exception | Extends | Thrown when |
|---|---|---|
| `Exception\InvalidArgumentException` | `\InvalidArgumentException` | A key is invalid or not a string; a batch is given an invalid key; a `DateInterval` cannot be converted; a Redis prefix is empty; a Redis client is configured incompatibly or is inside a transaction or pipeline |
| `Exception\CacheException` | `\RuntimeException` | A storage operation fails in `ErrorMode::Throw`; a `FileAdapter` directory is unusable, in either mode; a `RedisAdapter` client is not connected at construction in `ErrorMode::Throw` |
