<?php

declare(strict_types=1);

namespace Solo\Cache\Adapter;

use DateInterval;
use DirectoryIterator;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Internal\Key;
use Solo\Cache\Internal\PhpSerializer;
use Solo\Cache\Internal\Ttl;
use UnexpectedValueException;

class FileAdapter extends AbstractAdapter
{
    private const string ENTRY = 'solo-[a-f0-9]{64}\.cache';

    private const string TEMPORARY = '\.solo-cache-[a-f0-9]{16}\.tmp';

    /** Longest entry header: two 19-digit numbers, a space and a newline. */
    private const int HEADER_MAX = 40;

    private readonly string $cacheDirectory;

    public function __construct(string $cacheDirectory, ErrorMode $mode = ErrorMode::Throw)
    {
        parent::__construct($mode);

        if ($cacheDirectory === '' || str_contains($cacheDirectory, "\0")) {
            throw new CacheException('Invalid cache directory');
        }

        if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0700, true) && !is_dir($cacheDirectory)) {
            throw new CacheException('Failed to create cache directory: ' . $cacheDirectory);
        }

        $directory = realpath($cacheDirectory);
        if ($directory === false || !is_writable($directory)) {
            throw new CacheException('Cache directory is not writable: ' . $cacheDirectory);
        }

        $this->cacheDirectory = $directory;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        Key::validate($key);
        $content = $this->read($this->path($key));
        if (!is_string($content)) {
            return $default;
        }

        // The header is checked first, so an expired or truncated entry is never restored.
        $entry = $this->header($content, strlen($content));
        if ($entry === null || $this->isExpired($entry['expires'])) {
            return $default;
        }

        return PhpSerializer::decode(substr($content, $entry['offset']), $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        Key::validate($key);
        $seconds = Ttl::seconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $now = time();
        $expiresAt = $seconds === null ? 0 : $now + min($seconds, PHP_INT_MAX - $now);

        $payload = $this->encode($value);
        if ($payload === false) {
            return false;
        }
        $content = $expiresAt . ' ' . strlen($payload) . "\n" . $payload;

        return $this->withLock(function () use ($key, $content): bool {
            // A temporary file in the pool directory keeps rename() atomic; 'x' never reuses one.
            $temporary = $this->cacheDirectory . '/.solo-cache-' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = @fopen($temporary, 'xb');
            if ($handle === false) {
                return $this->fail('Failed to create a temporary cache file: ' . $temporary);
            }
            fclose($handle);

            $published = false;
            try {
                $path = $this->path($key);
                if (!@chmod($temporary, 0600) || @file_put_contents($temporary, $content) !== strlen($content)) {
                    return $this->fail('Failed to write cache file: ' . $temporary);
                }
                if (!@rename($temporary, $path)) {
                    return $this->fail('Failed to replace cache file: ' . $path);
                }

                $published = true;
                return true;
            } finally {
                if (!$published) {
                    @unlink($temporary);
                }
            }
        });
    }

    public function delete(string $key): bool
    {
        Key::validate($key);

        return $this->withLock(fn (): bool => $this->remove($this->path($key)));
    }

    public function clear(): bool
    {
        return $this->withLock(function (): bool {
            $files = $this->files(self::ENTRY . '|' . self::TEMPORARY);
            if ($files === false) {
                return false;
            }

            $success = true;
            foreach ($files as $file) {
                $success = $this->remove($file) && $success;
            }

            return $success;
        });
    }

    /** Remove expired or corrupt entries. Schedule this periodically for file caches. */
    public function gc(): int
    {
        $files = $this->files(self::ENTRY);
        if ($files === false) {
            return 0;
        }

        $deleted = 0;
        foreach ($files as $file) {
            // Only headers are read, so no application code runs. The check is repeated
            // under the lock because a writer may have replaced the entry in between.
            if ($this->stale($file) && $this->withLock(fn (): bool => $this->stale($file) && $this->remove($file))) {
                $deleted++;
            }
        }

        // Writers keep a temporary file only while they hold the lock, so one
        // seen under the lock was left behind by a writer that crashed.
        $this->withLock(function () use (&$deleted): bool {
            foreach ($this->files(self::TEMPORARY) ?: [] as $file) {
                if ($this->remove($file)) {
                    $deleted++;
                }
            }

            return true;
        });

        return $deleted;
    }

    private function path(string $key): string
    {
        return $this->cacheDirectory . '/solo-' . hash('sha256', $key) . '.cache';
    }

    /** @param int<0, max>|null $length */
    private function read(string $path, ?int $length = null): string|false|null
    {
        $content = @file_get_contents($path, false, null, 0, $length);
        if ($content !== false) {
            return $content;
        }

        if ($this->exists($path)) {
            // A writer may have published the file after the first open missed.
            $content = @file_get_contents($path, false, null, 0, $length);
            if ($content !== false) {
                return $content;
            }
            if ($this->exists($path)) {
                return $this->fail('Failed to read cache file: ' . $path);
            }
        }

        clearstatcache(true, $this->cacheDirectory);
        if (
            !is_dir($this->cacheDirectory)
            || (PHP_OS_FAMILY !== 'Windows' && !is_executable($this->cacheDirectory))
        ) {
            return $this->fail('Cannot access cache directory: ' . $this->cacheDirectory);
        }

        return null;
    }

    /**
     * Parses the "<expiry> <length>\n" header that precedes the serialized value. An expiry
     * of 0 means none, and the length must match the rest of the entry.
     *
     * @return array{expires: int, offset: int}|null
     */
    private function header(string $content, int $size): ?array
    {
        if (preg_match('/\A(\d{1,19}) (\d{1,19})\n/', $content, $match) !== 1) {
            return null;
        }

        $offset = strlen($match[0]);

        return (int) $match[2] === $size - $offset ? ['expires' => (int) $match[1], 'offset' => $offset] : null;
    }

    /**
     * Whether an entry is expired or damaged, judged by its header and size alone.
     *
     * @phpstan-impure Another process can replace the entry between checks.
     */
    private function stale(string $path): bool
    {
        $head = $this->read($path, self::HEADER_MAX);
        if (!is_string($head)) {
            return false;
        }

        clearstatcache(true, $path);
        $entry = $this->header($head, (int) @filesize($path));

        return $entry === null || $this->isExpired($entry['expires']);
    }

    private function isExpired(int $expires): bool
    {
        return $expires !== 0 && $expires <= time();
    }

    private function remove(string $path): bool
    {
        if (@unlink($path) || !$this->exists($path)) {
            return true;
        }

        return $this->fail('Failed to remove cache file: ' . $path);
    }

    /** @phpstan-impure Another process can replace the file between reads. */
    private function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }

    /** @return list<string>|false */
    private function files(string $names): array|false
    {
        try {
            $directory = new DirectoryIterator($this->cacheDirectory);
        } catch (UnexpectedValueException $exception) {
            return $this->fail('Failed to list cache directory: ' . $this->cacheDirectory, $exception);
        }

        $pattern = '/\A(?:' . $names . ')\z/';
        $files = [];
        foreach ($directory as $file) {
            if (preg_match($pattern, $file->getFilename()) === 1 && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * The lock file is permanent: removing it would allow concurrent processes to
     * lock different inodes. Reads use atomic snapshots and never remove entries.
     *
     * @param callable(): bool $operation
     */
    private function withLock(callable $operation): bool
    {
        $path = $this->cacheDirectory . '/.solo-cache.lock';
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            return $this->fail('Failed to open cache lock: ' . $path);
        }

        try {
            $stat = fstat($lock);
            $private = $stat !== false && ($stat['mode'] & 0777) === 0600;
            if (!($private || @chmod($path, 0600)) || !flock($lock, LOCK_EX)) {
                return $this->fail('Failed to acquire cache lock: ' . $path);
            }

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
