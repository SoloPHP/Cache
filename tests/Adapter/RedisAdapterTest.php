<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Adapter;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Solo\Cache\Adapter\RedisAdapter;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;

class RedisAdapterTest extends TestCase
{
    private Redis $redis;
    private RedisAdapter $adapter;
    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        $this->redis = new Redis();

        try {
            $connected = @$this->redis->connect('127.0.0.1', 6379);
            if (!$connected) {
                $this->markTestSkipped('Redis server is not available');
            }
            $this->redisAvailable = true;

            // Use a test database (15) to avoid conflicts
            $this->redis->select(15);
            $this->redis->flushDB();

            $this->adapter = new RedisAdapter($this->redis);
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis server is not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                $this->redis->flushDB();
                $this->redis->close();
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
    }

    public function testConstructorThrowsExceptionWhenNotConnected(): void
    {
        $redis = new Redis();

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis connection is not established');

        new RedisAdapter($redis);
    }

    public function testConstructorSetsSerializerOption(): void
    {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $redis->select(15);
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        new RedisAdapter($redis);

        $this->assertEquals(Redis::SERIALIZER_PHP, $redis->getOption(Redis::OPT_SERIALIZER));
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
        $this->assertTrue($this->adapter->set('key', 'value', 2));
        $this->assertEquals('value', $this->adapter->get('key'));

        sleep(3);
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

    public function testGetMultipleUsesMget(): void
    {
        // Set multiple keys
        $this->adapter->set('test1', 'value1');
        $this->adapter->set('test2', 'value2');
        $this->adapter->set('test3', 'value3');

        // Get multiple should use mGet internally (single Redis call)
        $result = $this->adapter->getMultiple(['test1', 'test2', 'test3']);

        $expected = [
            'test1' => 'value1',
            'test2' => 'value2',
            'test3' => 'value3',
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

        $this->assertTrue($this->adapter->setMultiple($values, 2));

        $this->assertEquals('value1', $this->adapter->get('key1'));
        $this->assertEquals('value2', $this->adapter->get('key2'));

        sleep(3);

        $this->assertNull($this->adapter->get('key1'));
        $this->assertNull($this->adapter->get('key2'));
    }

    public function testSetMultipleUsesMsetWithoutTtl(): void
    {
        $values = [
            'batch1' => 'value1',
            'batch2' => 'value2',
            'batch3' => 'value3',
        ];

        // Without TTL, should use mSet (atomic operation)
        $this->assertTrue($this->adapter->setMultiple($values));

        // Verify all values were set
        $result = $this->adapter->getMultiple(array_keys($values));
        $this->assertEquals($values, $result);
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

    public function testDeleteMultipleUsesDelWithArray(): void
    {
        $this->adapter->set('del1', 'value1');
        $this->adapter->set('del2', 'value2');
        $this->adapter->set('del3', 'value3');

        // Should use single del() call with array
        $this->assertTrue($this->adapter->deleteMultiple(['del1', 'del2', 'del3']));

        $this->assertNull($this->adapter->get('del1'));
        $this->assertNull($this->adapter->get('del2'));
        $this->assertNull($this->adapter->get('del3'));
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

    public function testKeyPrefixing(): void
    {
        $this->adapter->set('mykey', 'myvalue');

        // Check that key is prefixed in Redis
        $actualKey = 'cache:mykey';
        $rawValue = $this->redis->get($actualKey);

        $this->assertNotFalse($rawValue);
        $this->assertEquals('myvalue', $rawValue);
    }

    public function testErrorModeThrow(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->method('getOption')->willReturn(Redis::SERIALIZER_PHP);

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $redis->method('get')
            ->willThrowException(new RedisException('Connection lost'));

        $this->expectException(CacheException::class);
        $adapter->get('key');
    }

    public function testErrorModeFail(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->method('getOption')->willReturn(Redis::SERIALIZER_PHP);

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $redis->method('get')
            ->willThrowException(new RedisException('Connection lost'));

        $result = $adapter->get('key', 'default');
        $this->assertEquals('default', $result);
    }

    public function testSetMode(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->method('getOption')->willReturn(Redis::SERIALIZER_PHP);

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);
        $adapter->setMode(RedisAdapter::MODE_FAIL);

        $redis->method('get')
            ->willThrowException(new RedisException('Connection lost'));

        // Should not throw exception in MODE_FAIL
        $result = $adapter->get('key', 'fallback');
        $this->assertEquals('fallback', $result);
    }
}