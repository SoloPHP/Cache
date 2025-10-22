<?php

declare(strict_types=1);

namespace Solo\Cache\Adapter;

use DateInterval;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;

class FileAdapter implements CacheAdapterInterface
{
    private const KEY_PATTERN = '/^[a-zA-Z0-9_.]+$/';

    // Error handling modes
    public const MODE_THROW = 0;  // Throw exceptions on errors
    public const MODE_FAIL = 1;   // Return false/default on errors

    private int $mode;

    public function __construct(
        private readonly string $cacheDirectory,
        int $mode = self::MODE_THROW
    ) {
        $this->mode = $mode;
        $this->initializeCacheDirectory();
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

        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return $default;
        }

        $data = $this->loadCacheData($filePath);

        if ($data === null) {
            $this->delete($key);
            return $default;
        }

        if ($data['expires_at'] !== null && $data['expires_at'] < time()) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $expiresAt = $this->calculateExpiration($ttl);

        // If TTL is negative or zero, delete the key immediately
        if ($expiresAt !== null && $expiresAt <= time()) {
            return $this->delete($key);
        }

        $filePath = $this->getFilePath($key);

        $data = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        $serialized = serialize($data);

        $result = @file_put_contents($filePath, $serialized, LOCK_EX);

        if ($result === false) {
            if ($this->mode === self::MODE_THROW) {
                throw new CacheException("Failed to write cache file: {$filePath}");
            }
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return true;
        }

        return @unlink($filePath);
    }

    public function clear(): bool
    {
        $files = glob($this->cacheDirectory . '/*');

        if ($files === false) {
            return false;
        }

        $success = true;

        foreach ($files as $file) {
            if (is_file($file)) {
                $success = @unlink($file) && $success;
            }
        }

        return $success;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // Convert to array and remove duplicates
        $keyArray = is_array($keys) ? array_values($keys) : iterator_to_array($keys);
        $keyArray = array_unique($keyArray);

        $this->validateKeys($keyArray);

        if (empty($keyArray)) {
            return [];
        }

        $values = [];
        foreach ($keyArray as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $valueArray = is_array($values) ? $values : iterator_to_array($values);
        $this->validateIterable($valueArray);

        if (empty($valueArray)) {
            return true;
        }

        $success = true;
        foreach ($valueArray as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string');
            }

            try {
                $success = $this->set($key, $value, $ttl) && $success;
            } catch (CacheException $e) {
                if ($this->mode === self::MODE_THROW) {
                    throw $e;
                }
                $success = false;
            }
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

        $success = true;
        foreach ($keyArray as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return false;
        }

        // Check expiration without loading the value
        $data = $this->loadCacheData($filePath);

        if ($data === null) {
            $this->delete($key);
            return false;
        }

        if ($data['expires_at'] !== null && $data['expires_at'] < time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * Load and validate cache data from file
     * @return array{value: mixed, expires_at: ?int}|null
     */
    private function loadCacheData(string $filePath): ?array
    {
        $content = @file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        $data = @unserialize($content);

        if ($data === false || !is_array($data)) {
            return null;
        }

        if (!isset($data['value']) || !array_key_exists('expires_at', $data)) {
            return null;
        }

        /** @var array{value: mixed, expires_at: ?int} $data */
        return $data;
    }

    private function initializeCacheDirectory(): void
    {
        if (!is_dir($this->cacheDirectory)) {
            if (!@mkdir($this->cacheDirectory, 0755, true)) {
                throw new CacheException("Failed to create cache directory: {$this->cacheDirectory}");
            }
        }

        if (!is_writable($this->cacheDirectory)) {
            throw new CacheException("Cache directory is not writable: {$this->cacheDirectory}");
        }
    }

    private function getFilePath(string $key): string
    {
        return $this->cacheDirectory . '/' . md5($key) . '.cache';
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }

        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException(
                'Cache key contains invalid characters. ' .
                'Only alphanumeric characters, underscores, and dots are allowed.'
            );
        }
    }

    /**
     * @param iterable<mixed> $keys
     */
    private function validateKeys(iterable $keys): void
    {
        if (!is_iterable($keys)) {
            throw new InvalidArgumentException('Keys must be iterable');
        }

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string');
            }

            $this->validateKey($key);
        }
    }

    /**
     * @param iterable<mixed> $values
     */
    private function validateIterable(iterable $values): void
    {
        if (!is_iterable($values)) {
            throw new InvalidArgumentException('Values must be iterable');
        }
    }

    private function calculateExpiration(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new \DateTimeImmutable();
            $expiresAt = $now->add($ttl);
            return $expiresAt->getTimestamp();
        }

        if ($ttl <= 0) {
            return time();
        }

        return time() + $ttl;
    }

    /**
     * Garbage collection - remove expired cache files
     * Can be called periodically via cron or manually
     */
    public function gc(): int
    {
        $files = glob($this->cacheDirectory . '/*.cache');

        if ($files === false) {
            return 0;
        }

        $deleted = 0;
        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $data = $this->loadCacheData($file);

            if ($data === null || ($data['expires_at'] !== null && $data['expires_at'] < $now)) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
