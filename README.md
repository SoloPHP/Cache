# Solo Cache

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/cache.svg)](https://packagist.org/packages/solophp/cache)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

PSR-16 cache for PHP with file and Redis adapters. File writes publish atomically through a temporary file and `rename()`; a Redis pool lives under its own key prefix and sends each batch in one round trip. Values keep their types, so a cached `false` or `null` is a hit, and `ErrorMode` decides whether a failing backend throws or turns into a miss.

## Installation

```bash
composer require solophp/cache
```

Requires PHP 8.3+. The Redis adapter also needs the `redis` extension.

## Example

```php
use Solo\Cache\Adapter\FileAdapter;
use Solo\Cache\Adapter\RedisAdapter;

$cache = new FileAdapter(__DIR__ . '/var/cache/catalog');
$cache->set('product.42', ['name' => 'Desk'], 3600);
$product = $cache->get('product.42'); // ['name' => 'Desk']

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$cache = new RedisAdapter($redis, prefix: 'catalog:');
$cache->setMultiple(['product.42' => 'Desk', 'product.43' => 'Chair'], 600);
$cache->getMultiple(['product.42', 'product.44'], 'missing');
// ['product.42' => 'Desk', 'product.44' => 'missing']
```

Both adapters implement `Psr\SimpleCache\CacheInterface`, so application code type-hints the interface and the adapter is chosen where services are wired. Keys use ASCII letters, digits, `_`, `.` and `-`. Reads never delete files, so schedule `FileAdapter::gc()` to reclaim the space of expired entries.

## Documentation

**[Full Documentation](https://solophp.github.io/Cache/)**

- [Getting Started](https://solophp.github.io/Cache/guide/)
- [File Cache](https://solophp.github.io/Cache/guide/#file-cache)
- [Redis Cache](https://solophp.github.io/Cache/guide/#redis-cache)
- [Expiration](https://solophp.github.io/Cache/guide/#set-expiration)
- [Batches](https://solophp.github.io/Cache/guide/#work-in-batches)
- [Failure Modes](https://solophp.github.io/Cache/guide/#choose-a-failure-mode)
- [Application Wiring](https://solophp.github.io/Cache/guide/#wire-it-into-an-application)
- [API Reference](https://solophp.github.io/Cache/guide/api)
- [Behavior in Detail](https://solophp.github.io/Cache/guide/details)
- [Upgrading from 1.x](UPGRADE.md)
- [Changelog](CHANGELOG.md)

## License

MIT. See [LICENSE](LICENSE).
