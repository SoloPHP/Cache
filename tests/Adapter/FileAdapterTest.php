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
}