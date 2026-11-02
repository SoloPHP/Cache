# Getting started

Solo Cache implements [PSR-16 Simple Cache](https://www.php-fig.org/psr/psr-16/)
with two adapters: `FileAdapter` keeps one file per entry in a directory, and
`RedisAdapter` stores entries under a key prefix on a `Redis` client. Both
preserve value types, accept TTLs in seconds or as a `DateInterval`, and follow
the failure policy you choose.

## Install

PHP 8.3+ is required. The only runtime dependency is `psr/simple-cache`.

```sh
composer require solophp/cache
```

The Redis adapter also needs the [`redis` extension](https://github.com/phpredis/phpredis).
The file adapter works without it.

## Pick an adapter

### File cache

```php
use Solo\Cache\Adapter\FileAdapter;

$cache = new FileAdapter(__DIR__ . '/var/cache/catalog');
```

The constructor creates the directory if it is missing and fails at once with
`CacheException` if it cannot create it or write to it. Give each pool a
dedicated directory outside the web root, on a local filesystem that supports
`flock()` and atomic `rename()`. Every process that uses the pool must run as
the same OS user: the directory is created with mode `0700` and entries with
mode `0600`.

### Redis cache

```php
use Solo\Cache\Adapter\RedisAdapter;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379, 1.0);

$cache = new RedisAdapter($redis, prefix: 'catalog:');
```

Configure the connection, authentication, database and timeouts on the client.
The adapter requires a standalone client in normal command mode with the
native prefix, serializer and compression disabled, and rejects any other
configuration with `InvalidArgumentException`. Reserve one prefix per pool; the
default is `cache:`, and a prefix without a trailing `:` gains one.

## Read and write

```php
$cache->set('user.42', ['name' => 'Ada', 'roles' => ['admin']]);

$user = $cache->get('user.42');              // the array as stored
$missing = $cache->get('user.43', []);       // [] for a miss

$cache->has('user.42');                      // true
$cache->delete('user.42');                   // true, also for a missing key
$cache->clear();                             // empties this pool only
```

Values go through PHP serialization, so integers, floats, arrays and objects
come back with their types. A stored `false` or `null` is a hit: `get()`
returns it even when you pass another default, and `has()` returns `true`.

Keys may contain ASCII letters, digits, `_`, `.` and `-`. Use dots to build
hierarchies such as `user.42.profile`; PSR-16 reserves `:` and the other
characters `{}()/\@`. An invalid key raises `InvalidArgumentException`.
[Keys](details.md#keys) lists the exact rules.

## Set expiration

```php
$cache->set('rates', $rates, 600);                       // ten minutes
$cache->set('report', $report, new DateInterval('P1D')); // one day
$cache->set('config', $config);                          // no expiration
$cache->set('rates', $rates, 0);                         // deletes 'rates'
```

A `DateInterval` is converted to seconds from the current time. A TTL of zero
or less deletes the entry instead of storing it. An expired entry is a miss.

## Work in batches

```php
$cache->setMultiple(['product.42' => 'Desk', 'product.43' => 'Chair'], 600);

$products = $cache->getMultiple(['product.42', 'product.43', 'product.44'], 'missing');
// ['product.42' => 'Desk', 'product.43' => 'Chair', 'product.44' => 'missing']

$cache->deleteMultiple(['product.42', 'product.43']);
```

Batch methods accept arrays and any `Traversable`, including generators. Every
key is validated before the first entry changes, so an invalid key rejects the
whole batch. On Redis, `getMultiple()` is one `MGET`, `deleteMultiple()` one
`DEL`, and `setMultiple()` one `MSET`, or one pipeline of `SETEX` commands when
a TTL is given.

## Choose a failure mode

Storage fails: a disk fills up, a directory disappears, Redis restarts. The
`ErrorMode` passed to the constructor decides what the caller sees:

```php
use Solo\Cache\Adapter\ErrorMode;

$cache = new FileAdapter($directory);                        // ErrorMode::Throw
$optional = new RedisAdapter($redis, ErrorMode::Fail, 'catalog:');

$optional->setMode(ErrorMode::Throw);                        // change it at runtime
```

| | `ErrorMode::Throw` (default) | `ErrorMode::Fail` |
|---|---|---|
| `get()`, `getMultiple()` | Throw `CacheException` | Return the default |
| `has()` | Throws `CacheException` | Returns `false` |
| `set()`, `delete()`, `clear()` and the other batch methods | Throw `CacheException` | Return `false` |

Use `ErrorMode::Fail` for a cache the application can run without: an outage
becomes a series of misses. An invalid key, an invalid TTL and a misconfigured
Redis client throw `InvalidArgumentException` in both modes, because they are
bugs rather than outages.

## Depend on the interface

Both adapters implement `Psr\SimpleCache\CacheInterface`. Type-hint the
interface in application code and choose the adapter where services are wired:

```php
use Psr\SimpleCache\CacheInterface;

final class ProductCatalog
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function find(int $id): array
    {
        $key = 'product.' . $id;
        $product = $this->cache->get($key);

        if ($product === null) {
            $product = $this->load($id);
            $this->cache->set($key, $product, 600);
        }

        return $product;
    }
}
```

`Solo\Cache\Cache` is a thin readonly wrapper that forwards every call to any
PSR-16 implementation, for code that wants a concrete class to depend on.

## Clean up file caches

Reads never delete anything, so expired and corrupt files stay on disk until
they are replaced or removed. Schedule `gc()` from a cron job or a worker:

```php
$removed = $cache->gc(); // number of files removed
```

`gc()` removes expired and corrupt entries and the temporary files of writers
that crashed, and keeps everything else. `clear()` removes all of the pool's
entries at once. Neither touches files that the adapter did not create, nor
the `.solo-cache.lock` file, which must stay in place while workers use the
directory.

## Further reading

- [Public API reference](api.md)
- [Behavior in detail](details.md)
- [Upgrading from 1.x](https://github.com/SoloPHP/Cache/blob/main/UPGRADE.md)
