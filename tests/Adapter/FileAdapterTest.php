<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Adapter;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Solo\Cache\Adapter\FileAdapter;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;

class FileAdapterTest extends TestCase
{
    private string $cacheDirectory;
    private FileAdapter $adapter;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/cache_test_' . uniqid();
        $this->adapter = new FileAdapter($this->cacheDirectory);
    }

    protected function tearDown(): void
    {
        // Clean up test cache directory
        if (is_dir($this->cacheDirectory)) {
            $files = glob($this->cacheDirectory . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->cacheDirectory);
        }
    }

    public function testConstructorCreatesDirectory(): void
    {
        $this->assertDirectoryExists($this->cacheDirectory);
        $this->assertIsWritable($this->cacheDirectory);
    }

    public function testConstructorThrowsExceptionForInvalidDirectory(): void
    {
        $this->expectException(CacheException::class);
        new FileAdapter('/invalid/path/that/cannot/be/created');
    }

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->adapter->set('key', 'value'));
        $this->assertEquals('value', $this->adapter->get('key'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', $this->adapter->get('nonexistent', 'default'));
    }

    public function testSetWithTtl(): void
    {
        $this->assertTrue($this->adapter->set('key', 'value', 1));
        $this->assertEquals('value', $this->adapter->get('key'));

        sleep(2);
        $this->assertNull($this->adapter->get('key'));
    }

    public function testSetWithDateInterval(): void
    {
        $interval = new DateInterval('PT2S');
        $this->assertTrue($this->adapter->set('key', 'value', $interval));
        $this->assertEquals('value', $this->adapter->get('key'));

        sleep(3);
        $this->assertNull($this->adapter->get('key'));
    }

    public function testSetWithNegativeTtl(): void
    {
        $this->assertTrue($this->adapter->set('key', 'value', -1));
        $this->assertNull($this->adapter->get('key'));
    }

    public function testDelete(): void
    {
        $this->adapter->set('key', 'value');
        $this->assertTrue($this->adapter->delete('key'));
        $this->assertNull($this->adapter->get('key'));
    }

    public function testDeleteNonExistent(): void
    {
        $this->assertTrue($this->adapter->delete('nonexistent'));
    }

    public function testClear(): void
    {
        $this->adapter->set('key1', 'value1');
        $this->adapter->set('key2', 'value2');
        $this->adapter->set('key3', 'value3');

        $this->assertTrue($this->adapter->clear());

        $this->assertNull($this->adapter->get('key1'));
        $this->assertNull($this->adapter->get('key2'));
        $this->assertNull($this->adapter->get('key3'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->adapter->has('key'));

        $this->adapter->set('key', 'value');
        $this->assertTrue($this->adapter->has('key'));

        $this->adapter->delete('key');
        $this->assertFalse($this->adapter->has('key'));
    }

    public function testHasWithExpiredKey(): void
    {
        $this->adapter->set('key', 'value', 1);
        $this->assertTrue($this->adapter->has('key'));

        sleep(2);
        $this->assertFalse($this->adapter->has('key'));
    }

    public function testGetMultiple(): void
    {
        $this->adapter->set('key1', 'value1');
        $this->adapter->set('key2', 'value2');
        $this->adapter->set('key3', 'value3');

        $result = $this->adapter->getMultiple(['key1', 'key2', 'key3', 'key4']);

        $expected = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
            'key4' => null,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetMultipleWithDefault(): void
    {
        $this->adapter->set('key1', 'value1');

        $result = $this->adapter->getMultiple(['key1', 'key2'], 'default');

        $expected = [
            'key1' => 'value1',
            'key2' => 'default',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetMultipleWithDuplicates(): void
    {
        $this->adapter->set('key1', 'value1');

        $result = $this->adapter->getMultiple(['key1', 'key1', 'key2']);

        $expected = [
            'key1' => 'value1',
            'key2' => null,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testSetMultiple(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        $this->assertTrue($this->adapter->setMultiple($values));

        $this->assertEquals('value1', $this->adapter->get('key1'));
        $this->assertEquals('value2', $this->adapter->get('key2'));
        $this->assertEquals('value3', $this->adapter->get('key3'));
    }

    public function testSetMultipleWithTtl(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $this->assertTrue($this->adapter->setMultiple($values, 1));

        $this->assertEquals('value1', $this->adapter->get('key1'));
        $this->assertEquals('value2', $this->adapter->get('key2'));

        sleep(2);

        $this->assertNull($this->adapter->get('key1'));
        $this->assertNull($this->adapter->get('key2'));
    }

    public function testDeleteMultiple(): void
    {
        $this->adapter->set('key1', 'value1');
        $this->adapter->set('key2', 'value2');
        $this->adapter->set('key3', 'value3');

        $this->assertTrue($this->adapter->deleteMultiple(['key1', 'key3']));

        $this->assertNull($this->adapter->get('key1'));
        $this->assertEquals('value2', $this->adapter->get('key2'));
        $this->assertNull($this->adapter->get('key3'));
    }

    public function testDeleteMultipleWithDuplicates(): void
    {
        $this->adapter->set('key1', 'value1');
        $this->adapter->set('key2', 'value2');

        $this->assertTrue($this->adapter->deleteMultiple(['key1', 'key1', 'key2']));

        $this->assertNull($this->adapter->get('key1'));
        $this->assertNull($this->adapter->get('key2'));
    }

    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->get('invalid key with spaces');
    }

    public function testEmptyKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->get('');
    }

    public function testSpecialCharactersInKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->get('key@#$%');
    }

    public function testColonSeparatorInKey(): void
    {
        $this->assertTrue($this->adapter->set('user:123:profile', 'data'));
        $this->assertEquals('data', $this->adapter->get('user:123:profile'));
    }

    public function testNestedColonSeparatorInKey(): void
    {
        $this->assertTrue($this->adapter->set('app:cache:user:session:abc123', 'session_data'));
        $this->assertEquals('session_data', $this->adapter->get('app:cache:user:session:abc123'));
    }

    public function testComplexDataTypes(): void
    {
        $data = [
            'array' => [1, 2, 3],
            'object' => (object)['foo' => 'bar'],
            'nested' => [
                'deep' => [
                    'value' => 'test',
                ],
            ],
        ];

        $this->adapter->set('complex', $data);
        $retrieved = $this->adapter->get('complex');

        $this->assertEquals($data, $retrieved);
    }

    public function testGarbageCollection(): void
    {
        // Set some keys with different TTLs
        $this->adapter->set('keep', 'value', 3600);
        $this->adapter->set('expire1', 'value', 1);
        $this->adapter->set('expire2', 'value', 1);
        $this->adapter->set('no_ttl', 'value');

        sleep(2);

        // Run garbage collection
        $deleted = $this->adapter->gc();

        $this->assertEquals(2, $deleted);
        $this->assertEquals('value', $this->adapter->get('keep'));
        $this->assertEquals('value', $this->adapter->get('no_ttl'));
        $this->assertNull($this->adapter->get('expire1'));
        $this->assertNull($this->adapter->get('expire2'));
    }

    public function testErrorModeThrow(): void
    {
        $adapter = new FileAdapter($this->cacheDirectory, FileAdapter::MODE_THROW);

        // Force an error by making directory read-only
        chmod($this->cacheDirectory, 0444);

        $this->expectException(CacheException::class);
        $adapter->set('key', 'value');
    }

    public function testErrorModeFail(): void
    {
        $adapter = new FileAdapter($this->cacheDirectory, FileAdapter::MODE_FAIL);

        // Force an error by making directory read-only
        chmod($this->cacheDirectory, 0444);

        $result = $adapter->set('key', 'value');
        $this->assertFalse($result);
    }

    public function testSetMode(): void
    {
        $adapter = new FileAdapter($this->cacheDirectory, FileAdapter::MODE_THROW);
        $adapter->setMode(FileAdapter::MODE_FAIL);

        // Force an error
        chmod($this->cacheDirectory, 0444);

        // Should not throw exception in MODE_FAIL
        $result = $adapter->set('key', 'value');
        $this->assertFalse($result);
    }

    public function testGetWithCorruptedCacheData(): void
    {
        // Create a corrupted cache file
        $key = 'corrupted';
        $filePath = $this->cacheDirectory . '/' . md5($key) . '.cache';
        file_put_contents($filePath, 'invalid serialized data');

        $result = $this->adapter->get($key, 'default');
        $this->assertEquals('default', $result);

        // File should be deleted
        $this->assertFileDoesNotExist($filePath);
    }

    public function testGetWithInvalidCacheStructure(): void
    {
        // Create a cache file with invalid structure (missing expires_at)
        $key = 'invalid_structure';
        $filePath = $this->cacheDirectory . '/' . md5($key) . '.cache';
        file_put_contents($filePath, serialize(['value' => 'test']));

        $result = $this->adapter->get($key, 'default');
        $this->assertEquals('default', $result);
    }

    public function testGetMultipleWithEmptyArray(): void
    {
        $result = $this->adapter->getMultiple([]);
        $this->assertEquals([], $result);
    }

    public function testGetMultipleWithIterator(): void
    {
        $this->adapter->set('iter1', 'value1');
        $this->adapter->set('iter2', 'value2');

        $iterator = new \ArrayIterator(['iter1', 'iter2']);
        $result = $this->adapter->getMultiple($iterator);

        $expected = ['iter1' => 'value1', 'iter2' => 'value2'];
        $this->assertEquals($expected, $result);
    }

    public function testSetMultipleWithEmptyArray(): void
    {
        $result = $this->adapter->setMultiple([]);
        $this->assertTrue($result);
    }

    public function testSetMultipleWithIterator(): void
    {
        $iterator = new \ArrayIterator(['iter_key1' => 'value1', 'iter_key2' => 'value2']);
        $result = $this->adapter->setMultiple($iterator);

        $this->assertTrue($result);
        $this->assertEquals('value1', $this->adapter->get('iter_key1'));
        $this->assertEquals('value2', $this->adapter->get('iter_key2'));
    }

    public function testSetMultipleWithNonStringKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string');

        $this->adapter->setMultiple([0 => 'value1', 1 => 'value2']);
    }

    public function testSetMultipleInModeFailReturnsFalseOnError(): void
    {
        $adapter = new FileAdapter($this->cacheDirectory, FileAdapter::MODE_FAIL);

        // Make directory read-only to trigger write failure
        chmod($this->cacheDirectory, 0444);

        $result = $adapter->setMultiple(['key1' => 'value1', 'key2' => 'value2']);
        $this->assertFalse($result);
    }

    public function testDeleteMultipleWithEmptyArray(): void
    {
        $result = $this->adapter->deleteMultiple([]);
        $this->assertTrue($result);
    }

    public function testDeleteMultipleWithIterator(): void
    {
        $this->adapter->set('del_iter1', 'value1');
        $this->adapter->set('del_iter2', 'value2');

        $iterator = new \ArrayIterator(['del_iter1', 'del_iter2']);
        $result = $this->adapter->deleteMultiple($iterator);

        $this->assertTrue($result);
        $this->assertNull($this->adapter->get('del_iter1'));
        $this->assertNull($this->adapter->get('del_iter2'));
    }

    public function testGetMultipleWithNonStringKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string');

        $this->adapter->getMultiple([123, 456]);
    }

    public function testDeleteMultipleWithNonStringKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string');

        $this->adapter->deleteMultiple([123, 456]);
    }

    public function testHasWithCorruptedCacheData(): void
    {
        $key = 'has_corrupted';
        $filePath = $this->cacheDirectory . '/' . md5($key) . '.cache';
        file_put_contents($filePath, 'invalid data');

        $this->assertFalse($this->adapter->has($key));
        $this->assertFileDoesNotExist($filePath);
    }

    public function testSetWithZeroTtl(): void
    {
        $result = $this->adapter->set('zero_ttl', 'value', 0);
        $this->assertTrue($result);
        $this->assertNull($this->adapter->get('zero_ttl'));
    }

    public function testValidKeyWithAllAllowedCharacters(): void
    {
        $key = 'aA0_.:zZ9';
        $this->assertTrue($this->adapter->set($key, 'value'));
        $this->assertEquals('value', $this->adapter->get($key));
    }

    public function testConstructorThrowsExceptionForNonWritableDirectory(): void
    {
        $readOnlyDir = sys_get_temp_dir() . '/readonly_cache_' . uniqid();
        mkdir($readOnlyDir, 0555);

        try {
            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('Cache directory is not writable');
            new FileAdapter($readOnlyDir);
        } finally {
            chmod($readOnlyDir, 0755);
            rmdir($readOnlyDir);
        }
    }

    public function testGetWithUnreadableFile(): void
    {
        $key = 'unreadable';
        $this->adapter->set($key, 'value');

        $filePath = $this->cacheDirectory . '/' . md5($key) . '.cache';

        // Make file unreadable
        chmod($filePath, 0000);

        $result = $this->adapter->get($key, 'default');

        // Restore permissions for cleanup (file may have been deleted)
        if (file_exists($filePath)) {
            chmod($filePath, 0644);
        }

        $this->assertEquals('default', $result);
    }
}