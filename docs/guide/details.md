# Behavior in detail

Solo Cache stores values for later and does nothing else. [Getting started](index.md) shows the setup, and the [public API](api.md) describes the contracts. This page explains the rules, how each adapter uses its storage, and how the package is tested.

## Design decisions

- **PSR-16 and nothing on top.** Application code depends on `Psr\SimpleCache\CacheInterface`, so an adapter can be replaced by any other implementation. The only extra public method is `FileAdapter::gc()`.
- **A hit is a hit.** A stored `false` or `null` is returned as stored, even when the caller passes another default, and `has()` agrees with `get()`. Code never has to guess whether `false` meant "missing".
- **Reads never write.** `get()` and `has()` treat expired and corrupt entries as misses without deleting them, so readers stay lock-free. `gc()` reclaims the space.
- **Outages are a policy, bugs are not.** Whether a storage failure throws or falls back is up to the [error mode](api.md#errormode). An invalid key, TTL or client configuration throws in both modes.
- **Check first, then write.** Batch methods validate every key, and the Redis adapter serializes every value, before the first entry changes.
- **Leave the client alone.** The Redis adapter validates the options of the client it is given and never changes them, so the application keeps control of its connection.

## Structure

```text
src/
├── Cache.php                         Readonly wrapper around any PSR-16 cache
├── Adapter/
│   ├── AbstractAdapter.php           Error mode, has() and batch methods for adapters
│   ├── CacheAdapterInterface.php     Marker interface extending PSR-16
│   ├── ErrorMode.php                 Throw or fall back on storage failures
│   ├── FileAdapter.php               One file per entry, atomic replace, gc()
│   └── RedisAdapter.php              Prefixed keys on a phpredis client
├── Exception/
│   ├── CacheException.php            Storage failures
│   └── InvalidArgumentException.php  Invalid keys, TTLs and configuration
└── Internal/                         Key rules, TTL conversion and serialization
```

## Keys

A key matches `\A[A-Za-z0-9_.-]+\z`: one or more ASCII letters, digits, underscores, dots and hyphens. There is no length limit. PSR-16 requires `A-Z`, `a-z`, `0-9`, `_` and `.`, and reserves `{}()/\@:`, so keys stay portable to other implementations.

| Key | Valid |
|---|---|
| `user.42.profile`, `rates_EUR`, `feature-flags`, `0` | Yes |
| `''` | No: empty |
| `user:42` | No: `:` is reserved; use `user.42` |
| `a/b`, `a\b`, `a@b`, `a{b}`, `a(b)` | No: reserved characters |
| `a b`, `"key\n"`, `ключ` | No: space, trailing newline, non-ASCII |

In batches, `getMultiple()` and `deleteMultiple()` require each key to be a string, and duplicates are collapsed. `setMultiple()` accepts string keys and integer indexes, which stand for their decimal strings. When a generator yields the same key twice, the last value wins.

## Values and serialization

Values are stored with PHP's `serialize()`, so scalars, arrays and objects keep their types and nested references survive the round trip. A value is rejected as a storage failure when it cannot be stored without loss:

- resources and closed resources, at any depth;
- closures, generators, anonymous classes and other objects PHP refuses to serialize;
- incomplete objects (`__PHP_Incomplete_Class`) from an earlier failed restore.

An object that defines `__serialize()`, `__sleep()` or `Serializable` decides what it stores, and its internals are not inspected. When a value is rejected, the entry keeps its previous value.

Reading a payload that cannot be restored returns the default: corrupt bytes, a class that no longer exists at any depth, or an `__unserialize()` or `__wakeup()` hook that throws. A warning raised while a value is serialized or restored, by PHP or by a hook, counts as a failure. The serializer's own warnings count even when the caller uses `@` or `error_reporting(0)`. Warnings that a hook silences with `@` are left alone and passed to the application's error handler, together with notices, deprecations and other levels.

## Expiration

| TTL | Effect |
|---|---|
| `null` | The entry never expires |
| A positive integer | The entry expires after that many seconds |
| A `DateInterval` | Converted to seconds from the current time, so `P1M` is the length of the coming month |
| Zero or less | The entry is deleted instead of stored; the value is not serialized |

The file adapter stores the expiry as a Unix timestamp in the entry's header and treats the entry as expired once the current second reaches it, so expiration has a resolution of one second. Very long TTLs are capped: the file adapter at the largest timestamp PHP can hold, the Redis adapter at 9,000,000,000,000 seconds.

## File adapter

### Layout

```text
var/cache/catalog/
├── .solo-cache.lock        Pool lock, created by the first write, delete, clear() or gc(); never removed
├── solo-<sha256>.cache     One entry per key: SHA-256 of the key
└── .solo-cache-<hex>.tmp   Temporary file of a write in progress
```

Each entry file starts with a one-line header, then holds the serialized value:

```text
1793641200 28
a:1:{s:4:"name";s:4:"Desk";}
```

The header gives the expiry as a Unix timestamp, or `0` for none, and the length of the serialized value. A file whose header is malformed or whose length does not match is corrupt. Hashing keeps file names short and safe whatever the key. The adapter only touches files that match these names, so other files in the directory survive `clear()` and `gc()`.

### Writes

Serialization happens first, outside any lock. Then, holding an exclusive `flock()` on `.solo-cache.lock`, the adapter creates a temporary file with a random name in the pool directory, refusing to reuse an existing one, sets its mode to `0600`, writes the payload, checks the byte count and renames the file over the entry. `rename()` replaces the entry atomically, so a concurrent reader sees either the old file or the new one. If any step fails, the temporary file is removed.

The temporary file is always created in the pool directory itself, so the rename never crosses filesystems. If the directory has disappeared, the write fails.

### Reads

Readers open the entry file without a lock. A reader that already has the file open keeps reading the version it opened, even while a writer replaces it. A missing file is a miss. The header is checked before anything is restored, so an expired or truncated entry never runs a deserialization hook. A file that exists but cannot be read, or a pool directory that has disappeared or lost its search permission, is a storage failure.

### Garbage collection

Reads never delete, so `gc()` reclaims space. It lists the pool's entries without the lock and reads only each entry's header and size, never the value, so no application code runs and no class has to be loadable. An entry that is expired or corrupt is checked again under the lock, because a writer may have replaced it in the meantime, and deleted if it still qualifies.

An entry with a valid header whose value cannot be restored, for example because a class is only known to another release, stays until it expires or is rewritten. Reads treat it as a miss.

Finally, under the lock, it deletes temporary files. Writers hold the lock for the whole life of their temporary file, so one seen under the lock belongs to a writer that crashed, for example on a fatal error or a killed process.

### Permissions

The pool directory is created with mode `0700`, entries are written with `0600`, and the lock file is set to `0600`. All processes that share a pool must run as the same OS user.

## Redis adapter

### Namespaces

Each item lives at `prefix . key`. A prefix without a trailing `:` gains one, so `catalog` and `catalog:` name the same pool. Because item keys cannot contain `:`, one prefix can nest under another without collisions: `cache:` and `cache:users:` are separate pools.

`clear()` runs `SCAN` with `MATCH prefix*` and `COUNT 1000`, escaping `*`, `?`, `[`, `]` and `\` in the prefix so they match literally. It deletes only keys whose remainder after the prefix is a valid item key, which leaves nested pools such as `cache:users:1` alone when clearing `cache:`. Keys written while the scan runs may survive it.

### Client requirements

The adapter needs the raw string replies of a plain client, so it rejects:

- `Redis::OPT_PREFIX`: the adapter prefix replaces it, and `clear()` must see real key names;
- a serializer other than `Redis::SERIALIZER_NONE` or compression other than `Redis::COMPRESSION_NONE`: values are already serialized by the adapter;
- a client inside `multi()` or `pipeline()`: commands would be queued instead of answered.

Options are checked before every operation, so a client switched into a transaction later is caught too.

### Batches and partial writes

`getMultiple()` is one `MGET`, `deleteMultiple()` one `DEL`, and `setMultiple()` without a TTL one `MSET`, which Redis applies atomically. With a TTL, `setMultiple()` sends one `SETEX` per key in a single pipeline. A command the server rejects fails the batch, and if the connection breaks during the pipeline, keys sent before the break may already be written. A pipeline left open by a failure is discarded, so the client returns to normal mode.

### Errors and reconnection

A command that returns a server error, such as `WRONGTYPE` when the key holds a list, is a storage failure with the server's message, never a silent miss. `MGET` reports keys of another type as missing, following Redis.

When a connection drops, for example after a read timeout, phpredis marks it closed and reconnects on the next command. The adapter lets that command run, so a long-running worker recovers without being restarted. A client that has never been connected is reported as a failure without contacting a server.

## Failure handling

| Situation | `ErrorMode::Throw` | `ErrorMode::Fail` |
|---|---|---|
| Disk full, unreadable file, missing directory, lock failure | `CacheException` | Default or `false` |
| Redis connection lost, server error | `CacheException` | Default or `false` |
| Value cannot be serialized | `CacheException` | `false`; the old value stays |
| Payload cannot be restored, entry expired | Miss | Miss |
| Invalid key or TTL | `InvalidArgumentException` | `InvalidArgumentException` |
| Incompatible Redis client | `InvalidArgumentException` | `InvalidArgumentException` |
| `FileAdapter` directory unusable at construction | `CacheException` | `CacheException` |

## Security

- Restoring PHP objects runs their `__wakeup()` and `__unserialize()` hooks. Only trusted application code should be able to write to the cache directory or the Redis database.
- File pools are private to their OS user: `0700` directories and `0600` files and lock.
- Keys never reach the file system as names: entry files are named after the SHA-256 of the key.
- Redis key prefixes are escaped in `SCAN` patterns, so a prefix containing glob characters cannot clear another pool.

## Testing

`composer check` runs the PSR-12 check on `src/` and `tests/`, PHPStan at its maximum level on `src/`, and PHPUnit. `.github/workflows/ci.yml` validates `composer.json` and runs the checks on PHP 8.3, 8.4 and 8.5 with a Redis 7.4 service for the integration tests, and runs the test suite once more on PHP 8.3 without the Redis extension. `.github/workflows/docs.yml` builds this site.

To run the Redis integration tests locally, install `ext-redis` and set `REDIS_HOST` and `REDIS_PORT` to a disposable standalone server. The tests use their own random prefixes.

## Verified behavior

The test suite checks, among other things:

- the allowed and reserved key characters, trailing newlines, duplicate and generator keys, and integer indexes in `setMultiple()`;
- that an invalid key rejects a whole batch before any entry changes, on both adapters;
- round trips of `null`, `false`, binary strings, nested arrays, objects and recursive graphs, and the rejection of resources and closures at any depth;
- missing classes and throwing hooks as misses, and warnings silenced inside hooks;
- TTLs as integers and intervals, zero and negative TTLs, the expiry boundary and `PHP_INT_MAX`;
- both error modes for every kind of storage failure, and switching modes at runtime;
- atomic replacement while another process holds the old file open;
- the entry header, `gc()` with expired, malformed and truncated entries, entries whose classes this process cannot load, and temporary files left by crashed writers;
- that neither `get()` nor `gc()` restores an expired entry;
- `clear()` keeping the lock file and unrelated files;
- Redis commands and replies for every method, pipelined batches with a TTL, rejected commands, interrupted pipelines, reconnection after a timeout, and a never-connected client that must not reach a server on the default address;
- against a real Redis server: expiry, corrupt payloads, server errors, glob characters in prefixes, nested namespaces, rejected client options and extreme TTLs.
