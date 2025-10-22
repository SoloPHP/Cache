# PSR-16 Cache Library

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/cache.svg)](https://packagist.org/packages/solophp/cache)
[![License](https://img.shields.io/packagist/l/solophp/cache.svg)](https://github.com/solophp/cache/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/cache.svg)](https://packagist.org/packages/solophp/cache)
[![Tests](https://img.shields.io/github/actions/workflow/status/solophp/cache/tests.yml?label=tests)](https://github.com/solophp/cache/actions)

A flexible, PSR-16 compliant cache library with pluggable adapters for PHP 8.1+.

## Features

- Full PSR-16 (Simple Cache) compliance
- Pluggable adapter architecture
- File-based cache adapter
- Redis cache adapter
- Thread-safe file operations with `LOCK_EX`
- Automatic expiration handling
- Support for TTL as integer seconds or DateInterval

## Installation

```bash
composer require solophp/cache
```

For Redis support, ensure you have the Redis PHP extension installed:

```bash
pecl install redis
```

## Usage

### File Cache

```php
<?php

use Solo\Cache\Cache;
use Solo\Cache\Adapter\FileAdapter;

// Create file adapter
$adapter = new FileAdapter('/path/to/cache/directory');

// Create cache instance
$cache = new Cache($adapter);

// Store a value
$cache->set('user.123', ['name' => 'John', 'email' => 'john@example.com'], 3600);

// Retrieve a value
$user = $cache->get('user.123');

// Check if key exists
if ($cache->has('user.123')) {
    echo "Cache hit!";
}

// Delete a key
$cache->delete('user.123');

// Clear all cache
$cache->clear();
```

### Redis Cache

```php
<?php

use Solo\Cache\Cache;
use Solo\Cache\Adapter\RedisAdapter;
use Redis;

// Create Redis connection
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// Create Redis adapter (default MODE_THROW)
$adapter = new RedisAdapter($redis);

// Or with graceful error handling
$adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

// Create cache instance
$cache = new Cache($adapter);

// Use the cache
$cache->set('session.abc123', ['user_id' => 42], 7200);
$session = $cache->get('session.abc123');

// Switch error mode at runtime if needed
$adapter->setMode(RedisAdapter::MODE_FAIL);
```

### Multiple Operations

```php
<?php

// Set multiple values
$cache->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3',
], 3600);

// Get multiple values
$values = $cache->getMultiple(['key1', 'key2', 'key3'], 'default');

// Delete multiple values
$cache->deleteMultiple(['key1', 'key2']);
```

### Using DateInterval for TTL

```php
<?php

use DateInterval;

// Cache for 1 hour
$cache->set('key', 'value', new DateInterval('PT1H'));

// Cache for 1 day
$cache->set('key', 'value', new DateInterval('P1D'));

// Cache for 30 days
$cache->set('key', 'value', new DateInterval('P30D'));
```

## Adapters

### FileAdapter

File-based cache implementation suitable for low to medium load applications.

**Constructor Parameters:**
- `$cacheDirectory` (string): Directory path for storing cache files
- `$mode` (int): Error handling mode (optional, default: MODE_THROW)

**Features:**
- **Two error handling modes:**
  - `FileAdapter::MODE_THROW` - Throws exceptions on errors (default)
  - `FileAdapter::MODE_FAIL` - Returns false/default on errors (graceful degradation)
- **Optimized batch operations:**
  - Automatic deduplication of keys in batch operations
  - Early return for empty arrays
  - Graceful error handling in batch operations
- **Garbage collection method** `gc()` for manual cleanup of expired files
- **Refactored cache loading** with centralized `loadCacheData()` method
- Automatic directory creation with 0755 permissions
- Thread-safe operations with file locking (LOCK_EX)
- Automatic cleanup of expired entries on read operations
- Uses MD5 hashing for file names
- Runtime mode switching with `setMode()` method

**Usage with error modes:**
```php
// Default mode - throws exceptions
$adapter = new FileAdapter('/path/to/cache');

// Graceful mode - returns defaults on errors
$adapter = new FileAdapter('/path/to/cache', FileAdapter::MODE_FAIL);

// Manual garbage collection
$deletedFiles = $adapter->gc(); // Returns number of deleted expired files
```

**Limitations:**
- Not suitable for high-performance or distributed systems
- Consider Redis or Memcached for high-load scenarios

### RedisAdapter

Redis-based cache implementation for high-performance and distributed applications.

**Constructor Parameters:**
- `$redis` (Redis): Connected Redis instance
- `$mode` (int): Error handling mode (optional, default: MODE_THROW)

**Features:**
- **Optimized batch operations using Redis native commands** (mGet, mSet, del)
  - `getMultiple()` uses single mGet call instead of N individual gets
  - `setMultiple()` uses atomic mSet when no TTL is specified
  - `deleteMultiple()` uses single del call with array of keys
- **Native Redis serialization** (PHP serializer by default)
- **Two error handling modes:**
  - `RedisAdapter::MODE_THROW` - Throws exceptions on errors (default)
  - `RedisAdapter::MODE_FAIL` - Returns false/default on errors (graceful degradation)
- **Automatic deduplication** of keys in batch operations
- Native Redis TTL support
- Key prefixing (prefix: `cache:`)
- Runtime mode switching with `setMode()` method
- Suitable for distributed systems

## Key Validation

Both adapters enforce PSR-16 key requirements:
- Keys cannot be empty
- Only alphanumeric characters, underscores, and dots are allowed
- Pattern: `/^[a-zA-Z0-9_.]+$/`

Invalid keys will throw `Solo\Cache\Exception\InvalidArgumentException`.

## Exceptions

The library provides PSR-16 compliant exceptions:

- `Solo\Cache\Exception\InvalidArgumentException` - Invalid cache keys or arguments
- `Solo\Cache\Exception\CacheException` - Cache operation failures

## Requirements

- PHP 8.1 or higher
- psr/simple-cache ^3.0
- ext-redis

## License

MIT

