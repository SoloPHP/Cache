---
layout: home

hero:
  name: Solo Cache
  text: PSR-16 caching for files and Redis.
  tagline: Store any serializable value with a TTL. File writes publish atomically, a cached false or null comes back as a hit, and you decide whether a broken backend throws or falls back.
  image:
    src: /logo.svg
    alt: Solo Cache
  actions:
    - theme: brand
      text: Get started
      link: /guide/
    - theme: alt
      text: API reference
      link: /guide/api

features:
  - icon: 🔌
    title: Plain PSR-16
    details: Both adapters implement Psr\SimpleCache\CacheInterface. Type-hint the interface and move from the file cache to Redis without touching the calling code.
  - icon: 📁
    title: Atomic file writes
    details: Each write goes to a private temporary file that replaces the entry with rename(). A reader sees the old value or the new one, never half of either.
  - icon: 🧭
    title: Redis namespaces
    details: Every pool lives under its own prefix. clear() scans only that namespace, treats glob characters literally and leaves nested namespaces alone.
  - icon: 🎯
    title: Types survive
    details: Values round-trip through PHP serialization. A stored false or null is a hit, and has() always agrees with get().
  - icon: 🚦
    title: Your failure policy
    details: ErrorMode::Throw raises CacheException when storage fails. ErrorMode::Fail turns an unavailable cache into a miss. Invalid keys throw in both modes.
  - icon: ✅
    title: Checked before written
    details: Batch methods validate every key before the first write, and the Redis adapter serializes the whole batch first. setMultiple() with a TTL reaches Redis in one pipeline.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #ef4444 0%, #8b5cf6 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #ef444430 0%, #8b5cf630 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Store and read

```php
use Solo\Cache\Adapter\FileAdapter;

$cache = new FileAdapter(__DIR__ . '/var/cache/catalog');

$cache->set('product.42', ['name' => 'Desk', 'price' => 249], 3600);
$product = $cache->get('product.42');        // ['name' => 'Desk', 'price' => 249]

$cache->set('feature.beta', false);
$cache->get('feature.beta', 'unknown');      // false: a stored false is a hit
$cache->get('feature.gamma', 'unknown');     // 'unknown'
```

Swap in `RedisAdapter` with a connected `Redis` client and the same calls work
against Redis. Read the [getting-started guide](./guide/index.md) for both
adapters, expiration, batches and failure handling.

## Installation

PHP **8.3+** is required. The only runtime dependency is `psr/simple-cache`;
the Redis adapter also needs the `redis` extension.

```sh
composer require solophp/cache
```

Continue with the [getting-started guide](./guide/index.md).
