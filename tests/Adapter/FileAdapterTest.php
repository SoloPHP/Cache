<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Adapter;

use DateInterval;
use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use Solo\Cache\Adapter\ErrorMode;
use Solo\Cache\Adapter\FileAdapter;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;
use Solo\Cache\Tests\Fixtures\RefreshingValue;

final class FileAdapterTest extends TestCase
{
    private string $directory;
    private FileAdapter $cache;

    #[\Override]
    protected function setUp(): void
    {
        // Brackets are legal directory names and must not become a glob pattern.
        $this->directory = sys_get_temp_dir() . '/solo-cache[test]-' . bin2hex(random_bytes(8));
        $this->cache = new FileAdapter($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (new DirectoryIterator($this->directory) as $file) {
            if (!$file->isDot()) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
        }
        rmdir($this->directory);
    }

    public function testInvalidDirectoryFailsDuringConstruction(): void
    {
        $file = $this->directory . '/regular-file';
        file_put_contents($file, '');
        $this->expectException(CacheException::class);
        new FileAdapter($file . '/cache', ErrorMode::Fail);
    }

    public function testValuesKeepTheirTypesAcrossInstances(): void
    {
        $values = ['null' => null, 'false' => false, 'int' => 42, 'array' => [1, false], 'binary' => "\0\xFF"];
        self::assertTrue($this->cache->setMultiple($values));
        $reader = new FileAdapter($this->directory);
        foreach ($values as $key => $value) {
            self::assertTrue($reader->has($key));
            self::assertSame($value, $reader->get($key, 'miss'));
        }
        self::assertFalse($reader->has('absent'));
        self::assertSame('miss', $reader->get('absent', 'miss'));
        self::assertTrue($reader->delete('absent'));
    }

    public function testPositiveTtlIsRecordedAndExtremeTtlDoesNotOverflow(): void
    {
        $before = time();
        self::assertTrue($this->cache->set('hour', 'value', new DateInterval('PT1H')));
        $expiresAt = (int) strtok((string) file_get_contents($this->path('hour')), ' ');
        self::assertGreaterThanOrEqual($before + 3600, $expiresAt);
        self::assertLessThanOrEqual(time() + 3600, $expiresAt);
        self::assertTrue($this->cache->set('long', 'value', PHP_INT_MAX));
        self::assertSame('value', $this->cache->get('long'));
    }

    public function testEntryIsAHeaderFollowedByTheSerializedValue(): void
    {
        self::assertTrue($this->cache->set('plain', ['a' => false]));
        self::assertSame($this->entry(serialize(['a' => false])), file_get_contents($this->path('plain')));
    }

    public function testNonpositiveTtlDeletesWithoutSerializingTheReplacement(): void
    {
        foreach ([0, -1, new DateInterval('PT0S')] as $ttl) {
            self::assertTrue($this->cache->set('key', 'before'));
            self::assertTrue($this->cache->set('key', static fn () => null, $ttl));
            self::assertFalse($this->cache->has('key'));
        }
    }

    public function testGcRemovesOnlyExpiredAndCorruptOwnedEntries(): void
    {
        self::assertTrue($this->cache->set('live', 'keep'));
        file_put_contents($this->path('boundary'), $this->entry(serialize('stale'), time()));
        file_put_contents($this->path('invalid'), 'garbled');
        file_put_contents($this->path('deadline'), "soon 4\nb:0;");
        file_put_contents($this->path('truncated'), substr($this->entry(serialize('cut short')), 0, -2));
        file_put_contents($this->directory . '/foreign.cache', 'data');
        foreach (['boundary', 'invalid', 'deadline', 'truncated'] as $key) {
            self::assertSame('miss', $this->cache->get($key, 'miss'));
            self::assertFalse($this->cache->has($key));
            self::assertFileExists($this->path($key));
        }
        self::assertSame(4, $this->cache->gc());
        self::assertSame(0, $this->cache->gc());
        self::assertSame('keep', $this->cache->get('live'));
        self::assertFileExists($this->directory . '/foreign.cache');
    }

    public function testExpiredEntriesAreNeverRestored(): void
    {
        $expired = $this->entry(serialize(new RefreshingValue($this->directory)), time() - 1);
        file_put_contents($this->path('expired'), $expired);
        self::assertSame('miss', $this->cache->get('expired', 'miss'));
        self::assertSame(1, $this->cache->gc());
        // RefreshingValue::__wakeup() writes 'refresh', so it never ran.
        self::assertFalse($this->cache->has('refresh'));
    }

    public function testGcKeepsLiveEntriesWhoseClassesThisProcessCannotLoad(): void
    {
        $unknown = 'O:28:"SoloCacheMissingFixtureClass":0:{}';
        file_put_contents($this->path('live'), $this->entry($unknown));
        file_put_contents($this->path('stale'), $this->entry($unknown, 1));
        self::assertSame(1, $this->cache->gc());
        self::assertFileExists($this->path('live'));
        self::assertFileDoesNotExist($this->path('stale'));
        self::assertSame('miss', $this->cache->get('live', 'miss'));
    }

    public function testGcAndClearRemoveTemporaryFilesLeftByCrashedWriters(): void
    {
        $orphan = $this->directory . '/.solo-cache-0123456789abcdef.tmp';
        file_put_contents($orphan, 'partial');
        self::assertSame(1, $this->cache->gc());
        self::assertFileDoesNotExist($orphan);
        file_put_contents($orphan, 'partial');
        self::assertTrue($this->cache->clear());
        self::assertFileDoesNotExist($orphan);
        self::assertFileExists($this->lockPath());
    }

    public function testLockFileIsMadePrivateBeforeLocking(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions are required');
        }

        touch($this->lockPath());
        chmod($this->lockPath(), 0644);
        self::assertTrue($this->cache->set('key', 'value'));
        clearstatcache(true, $this->lockPath());
        self::assertSame(0600, fileperms($this->lockPath()) & 0777);
    }

    public function testMissingPoolDirectoryUsesTheConfiguredReadErrorMode(): void
    {
        rmdir($this->directory);
        try {
            $this->cache->setMode(ErrorMode::Fail);
            self::assertFalse($this->cache->has('key'));
            self::assertSame('fallback', $this->cache->get('key', 'fallback'));
            $this->cache->setMode(ErrorMode::Throw);
            $this->expectException(CacheException::class);
            $this->cache->get('key');
        } finally {
            mkdir($this->directory, 0700);
        }
    }

    public function testClearRemovesOnlyOwnedFilesAndPreservesLock(): void
    {
        self::assertTrue($this->cache->set('key', 'data'));
        $lock = $this->lockPath();
        $inode = fileinode($lock);
        $unrelated = ['readme.txt', 'foreign.cache', 'solo-invalid.cache', md5('old') . '.cache'];
        foreach ($unrelated as $filename) {
            file_put_contents($this->directory . '/' . $filename, 'keep');
        }
        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('key'));
        clearstatcache(true, $lock);
        self::assertSame($inode, fileinode($lock));
        foreach ($unrelated as $filename) {
            self::assertSame('keep', file_get_contents($this->directory . '/' . $filename));
        }
    }

    public function testSerializationFailurePreservesThePreviousValueInBothModes(): void
    {
        $this->cache->set('key', 'old');
        $this->cache->setMode(ErrorMode::Fail);
        self::assertFalse($this->cache->set('key', static fn () => 1));
        self::assertSame('old', $this->cache->get('key'));
        $this->cache->setMode(ErrorMode::Throw);
        $this->expectException(CacheException::class);
        $this->cache->set('key', static fn () => 1);
    }

    public function testFailedReplacementCleansUpItsTemporaryFile(): void
    {
        mkdir($this->path('blocked'));
        $this->cache->setMode(ErrorMode::Fail);
        self::assertFalse($this->cache->set('blocked', 'value'));
        self::assertFalse($this->cache->delete('blocked'));
        self::assertSame(['.', '..', '.solo-cache.lock', basename($this->path('blocked'))], scandir($this->directory));
        $this->cache->setMode(ErrorMode::Throw);
        $this->expectException(CacheException::class);
        $this->cache->set('blocked', 'value');
    }

    public function testFailedMutationsHonorTheSelectedMode(): void
    {
        $this->cache->set('key', 'old');
        $lock = $this->lockPath();
        unlink($lock);
        mkdir($lock);
        $this->cache->setMode(ErrorMode::Fail);
        self::assertFalse($this->cache->set('key', 'new'));
        self::assertFalse($this->cache->delete('key'));
        self::assertFalse($this->cache->clear());
        self::assertSame('old', $this->cache->get('key'));
        $this->cache->setMode(ErrorMode::Throw);
        $this->expectException(CacheException::class);
        $this->cache->delete('key');
    }

    public function testBulkOperationsValidateBeforeMutating(): void
    {
        $this->cache->set('valid', 'before');
        try {
            $this->cache->setMultiple(['valid' => 'after', 'legacy:key' => 'invalid']);
            self::fail('The reserved character should have been rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame('before', $this->cache->get('valid'));
        }
        $keys = (static function () {
            yield 0 => 'valid';
            yield 0 => 'missing';
        })();
        self::assertSame(['valid' => 'before', 'missing' => 'miss'], $this->cache->getMultiple($keys, 'miss'));
    }

    public function testReaderHoldingAnOpenFileSeesAStableSnapshotWhenAnotherProcessReplacesIt(): void
    {
        if (!function_exists('proc_open') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('This test requires proc_open and POSIX file replacement.');
        }
        $this->cache->set('shared', 'old');
        $snapshot = fopen($this->path('shared'), 'rb');
        self::assertIsResource($snapshot);
        try {
            $worker = <<<'WORKER'
                require $argv[1];
                $cache = new \Solo\Cache\Adapter\FileAdapter($argv[2]);
                exit($cache->set('shared', 'new') ? 0 : 1);
                WORKER;
            $process = proc_open(
                [PHP_BINARY, '-r', $worker, dirname(__DIR__, 2) . '/vendor/autoload.php', $this->directory],
                [],
                $pipes
            );
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process));
            self::assertSame('new', $this->cache->get('shared'));
            $old = (string) stream_get_contents($snapshot);
            self::assertSame('old', unserialize(substr($old, strpos($old, "\n") + 1)));
        } finally {
            fclose($snapshot);
        }
    }

    private function entry(string $payload, int $expiresAt = 0): string
    {
        return $expiresAt . ' ' . strlen($payload) . "\n" . $payload;
    }

    private function lockPath(): string
    {
        return $this->directory . '/.solo-cache.lock';
    }

    private function path(string $key): string
    {
        return $this->directory . '/solo-' . hash('sha256', $key) . '.cache';
    }
}
