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
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension is not installed');
        }
    }

    private function createConnectedRedisMock(): Redis
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->method('getOption')->willReturn(Redis::SERIALIZER_PHP);
        return $redis;
    }

    public function testConstructorThrowsExceptionWhenNotConnected(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(false);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis connection is not established');

        new RedisAdapter($redis);
    }

    public function testConstructorInModeFailDoesNotThrowWhenNotConnected(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(false);
        $redis->method('getOption')->willReturn(Redis::SERIALIZER_PHP);

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);
        $this->assertInstanceOf(RedisAdapter::class, $adapter);
    }

    public function testConstructorSetsSerializerOption(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->method('getOption')->willReturn(Redis::SERIALIZER_NONE);
        $redis->expects($this->once())
            ->method('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        new RedisAdapter($redis);
    }

    public function testSetAndGet(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('set')
            ->with('cache:key', 'value')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->with('cache:key')
            ->willReturn('value');

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->set('key', 'value'));
        $this->assertEquals('value', $adapter->get('key'));
    }

    public function testGetWithDefault(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('get')->willReturn(false);

        $adapter = new RedisAdapter($redis);

        $this->assertEquals('default', $adapter->get('nonexistent', 'default'));
    }

    public function testSetWithTtl(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('setex')
            ->with('cache:key', 3600, 'value')
            ->willReturn(true);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->set('key', 'value', 3600));
    }

    public function testSetWithDateInterval(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('setex')
            ->willReturn(true);

        $adapter = new RedisAdapter($redis);
        $interval = new DateInterval('PT1H');

        $this->assertTrue($adapter->set('key', 'value', $interval));
    }

    public function testSetWithNegativeTtl(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('del')
            ->with('cache:key')
            ->willReturn(1);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->set('key', 'value', -1));
    }

    public function testSetWithZeroTtl(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('del')
            ->with('cache:key')
            ->willReturn(1);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->set('key', 'value', 0));
    }

    public function testDelete(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('del')
            ->with('cache:key')
            ->willReturn(1);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->delete('key'));
    }

    public function testDeleteNonExistent(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('del')->willReturn(0);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->delete('nonexistent'));
    }

    public function testClear(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('keys')
            ->with('cache:*')
            ->willReturn(['cache:key1', 'cache:key2']);
        $redis->expects($this->once())
            ->method('del')
            ->with(['cache:key1', 'cache:key2'])
            ->willReturn(2);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->clear());
    }

    public function testClearWithNoKeys(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('keys')->willReturn([]);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->clear());
    }

    public function testHas(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('exists')
            ->with('cache:key')
            ->willReturn(1);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->has('key'));
    }

    public function testHasReturnsFalseWhenKeyDoesNotExist(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('exists')->willReturn(0);

        $adapter = new RedisAdapter($redis);

        $this->assertFalse($adapter->has('nonexistent'));
    }

    public function testHasWithBooleanResult(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('exists')->willReturn(false);

        $adapter = new RedisAdapter($redis);

        $this->assertFalse($adapter->has('key'));
    }

    public function testGetMultiple(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('mGet')
            ->with(['cache:key1', 'cache:key2', 'cache:key3'])
            ->willReturn(['value1', 'value2', false]);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->getMultiple(['key1', 'key2', 'key3']);

        $expected = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => null,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetMultipleWithDefault(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mGet')->willReturn(['value1', false]);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->getMultiple(['key1', 'key2'], 'default');

        $expected = [
            'key1' => 'value1',
            'key2' => 'default',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetMultipleWithEmptyArray(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $result = $adapter->getMultiple([]);

        $this->assertEquals([], $result);
    }

    public function testGetMultipleWithDuplicates(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('mGet')
            ->with(['cache:key1', 'cache:key2'])
            ->willReturn(['value1', false]);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->getMultiple(['key1', 'key1', 'key2']);

        $expected = [
            'key1' => 'value1',
            'key2' => null,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetMultipleWithIterator(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mGet')->willReturn(['value1', 'value2']);

        $adapter = new RedisAdapter($redis);

        $iterator = new \ArrayIterator(['key1', 'key2']);
        $result = $adapter->getMultiple($iterator);

        $expected = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testSetMultiple(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('mSet')
            ->with([
                'cache:key1' => 'value1',
                'cache:key2' => 'value2',
            ])
            ->willReturn(true);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->setMultiple([
            'key1' => 'value1',
            'key2' => 'value2',
        ]);

        $this->assertTrue($result);
    }

    public function testSetMultipleWithEmptyArray(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $result = $adapter->setMultiple([]);

        $this->assertTrue($result);
    }

    public function testSetMultipleWithTtl(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->exactly(2))
            ->method('setex')
            ->willReturn(true);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->setMultiple([
            'key1' => 'value1',
            'key2' => 'value2',
        ], 3600);

        $this->assertTrue($result);
    }

    public function testSetMultipleWithNegativeTtl(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('del')
            ->willReturn(2);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->setMultiple([
            'key1' => 'value1',
            'key2' => 'value2',
        ], -1);

        $this->assertTrue($result);
    }

    public function testSetMultipleWithIterator(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mSet')->willReturn(true);

        $adapter = new RedisAdapter($redis);

        $iterator = new \ArrayIterator(['key1' => 'value1', 'key2' => 'value2']);
        $result = $adapter->setMultiple($iterator);

        $this->assertTrue($result);
    }

    public function testSetMultipleWithNonStringKeyThrowsException(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string');

        $adapter->setMultiple([0 => 'value1', 1 => 'value2']);
    }

    public function testSetMultipleWithTtlAndNonStringKeyThrowsException(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string');

        $adapter->setMultiple([0 => 'value1'], 3600);
    }

    public function testDeleteMultiple(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('del')
            ->with(['cache:key1', 'cache:key2'])
            ->willReturn(2);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->deleteMultiple(['key1', 'key2']);

        $this->assertTrue($result);
    }

    public function testDeleteMultipleWithEmptyArray(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $result = $adapter->deleteMultiple([]);

        $this->assertTrue($result);
    }

    public function testDeleteMultipleWithDuplicates(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('del')
            ->with($this->callback(function ($keys) {
                return count($keys) === 2;
            }))
            ->willReturn(2);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->deleteMultiple(['key1', 'key1', 'key2']);

        $this->assertTrue($result);
    }

    public function testDeleteMultipleWithIterator(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('del')->willReturn(2);

        $adapter = new RedisAdapter($redis);

        $iterator = new \ArrayIterator(['key1', 'key2']);
        $result = $adapter->deleteMultiple($iterator);

        $this->assertTrue($result);
    }

    public function testInvalidKeyThrowsException(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $this->expectException(InvalidArgumentException::class);
        $adapter->get('invalid key with spaces');
    }

    public function testEmptyKeyThrowsException(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $this->expectException(InvalidArgumentException::class);
        $adapter->get('');
    }

    public function testSpecialCharactersInKeyThrowsException(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $this->expectException(InvalidArgumentException::class);
        $adapter->get('key@#$%');
    }

    public function testColonSeparatorInKey(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('set')->willReturn(true);
        $redis->method('get')->willReturn('data');

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->set('user:123:profile', 'data'));
        $this->assertEquals('data', $adapter->get('user:123:profile'));
    }

    public function testKeyPrefixing(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('set')
            ->with('cache:mykey', 'myvalue')
            ->willReturn(true);

        $adapter = new RedisAdapter($redis);

        $adapter->set('mykey', 'myvalue');
    }

    public function testSetMode(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('get')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);
        $adapter->setMode(RedisAdapter::MODE_FAIL);

        $result = $adapter->get('key', 'fallback');
        $this->assertEquals('fallback', $result);
    }

    public function testGetThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('get')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->get('key');
    }

    public function testGetReturnsDefaultInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('get')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $result = $adapter->get('key', 'default');
        $this->assertEquals('default', $result);
    }

    public function testSetThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('set')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->set('key', 'value');
    }

    public function testSetReturnsFalseInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('set')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $result = $adapter->set('key', 'value');
        $this->assertFalse($result);
    }

    public function testGetMultipleThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mGet')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->getMultiple(['key1', 'key2']);
    }

    public function testGetMultipleReturnsDefaultsInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mGet')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $result = $adapter->getMultiple(['key1', 'key2'], 'default');

        $expected = [
            'key1' => 'default',
            'key2' => 'default',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testSetMultipleThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mSet')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->setMultiple(['key1' => 'value1']);
    }

    public function testSetMultipleReturnsFalseInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mSet')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $result = $adapter->setMultiple(['key1' => 'value1']);
        $this->assertFalse($result);
    }

    public function testDeleteMultipleThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('del')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->deleteMultiple(['key1', 'key2']);
    }

    public function testDeleteMultipleReturnsFalseInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('del')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $result = $adapter->deleteMultiple(['key1', 'key2']);
        $this->assertFalse($result);
    }

    public function testGetMultipleWithNonStringKeyThrowsException(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string');

        $adapter->getMultiple([123, 456]);
    }

    public function testDeleteMultipleWithNonStringKeyThrowsException(): void
    {
        $redis = $this->createConnectedRedisMock();

        $adapter = new RedisAdapter($redis);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must be a string');

        $adapter->deleteMultiple([123, 456]);
    }

    public function testValidKeyWithAllAllowedCharacters(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('set')->willReturn(true);
        $redis->method('get')->willReturn('value');

        $adapter = new RedisAdapter($redis);

        $key = 'aA0_.:zZ9';
        $this->assertTrue($adapter->set($key, 'value'));
        $this->assertEquals('value', $adapter->get($key));
    }
}
