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

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);
        $this->assertInstanceOf(RedisAdapter::class, $adapter);
    }

    public function testConstructorDisablesBuiltInSerializer(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->expects($this->once())
            ->method('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        new RedisAdapter($redis);
    }

    public function testConstructorDoesNotSetSerializerWhenNotConnected(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('isConnected')->willReturn(false);
        $redis->expects($this->never())
            ->method('setOption');

        new RedisAdapter($redis, RedisAdapter::MODE_FAIL);
    }

    public function testSetAndGet(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('set')
            ->with('cache:key', serialize('value'))
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('get')
            ->with('cache:key')
            ->willReturn(serialize('value'));

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

    public function testGetWithFalseValue(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('get')->willReturn(serialize(false));

        $adapter = new RedisAdapter($redis);

        $this->assertFalse($adapter->get('key', 'default'));
    }

    public function testSetWithTtl(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('setex')
            ->with('cache:key', 3600, serialize('value'))
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

    public function testDeleteThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('del')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->delete('key');
    }

    public function testDeleteReturnsFalseInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('del')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $this->assertFalse($adapter->delete('key'));
    }

    public function testClear(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('scan')
            ->willReturnCallback(function (&$iterator, $pattern, $count) {
                $this->assertEquals('cache:*', $pattern);
                $iterator = 0;
                return ['cache:key1', 'cache:key2'];
            });
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
        $redis->method('scan')
            ->willReturnCallback(function (&$iterator) {
                $iterator = 0;
                return false;
            });

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->clear());
    }

    public function testClearWithMultipleScanIterations(): void
    {
        $redis = $this->createConnectedRedisMock();
        $callCount = 0;
        $redis->method('scan')
            ->willReturnCallback(function (&$iterator) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $iterator = 42; // More keys to scan
                    return ['cache:key1'];
                }
                $iterator = 0; // Done
                return ['cache:key2'];
            });
        $redis->expects($this->exactly(2))
            ->method('del');

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->clear());
    }

    public function testClearThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('scan')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->clear();
    }

    public function testClearReturnsFalseInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('scan')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $this->assertFalse($adapter->clear());
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

    public function testHasThrowsExceptionInModeThrow(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('exists')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW);

        $this->expectException(CacheException::class);
        $adapter->has('key');
    }

    public function testHasReturnsFalseInModeFail(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('exists')->willThrowException(new RedisException('Connection lost'));

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_FAIL);

        $this->assertFalse($adapter->has('key'));
    }

    public function testGetMultiple(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('mGet')
            ->with(['cache:key1', 'cache:key2', 'cache:key3'])
            ->willReturn([serialize('value1'), serialize('value2'), false]);

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
        $redis->method('mGet')->willReturn([serialize('value1'), false]);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->getMultiple(['key1', 'key2'], 'default');

        $expected = [
            'key1' => 'value1',
            'key2' => 'default',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testGetMultipleWithFalseValue(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('mGet')->willReturn([serialize(false), false]);

        $adapter = new RedisAdapter($redis);

        $result = $adapter->getMultiple(['key1', 'key2'], 'default');

        $expected = [
            'key1' => false,
            'key2' => 'default',
        ];

        $this->assertSame($expected, $result);
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
            ->willReturn([serialize('value1'), false]);

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
        $redis->method('mGet')->willReturn([serialize('value1'), serialize('value2')]);

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
                'cache:key1' => serialize('value1'),
                'cache:key2' => serialize('value2'),
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

    public function testDeleteMultipleWithNonExistentKeys(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('del')->willReturn(0);

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->deleteMultiple(['nonexistent1', 'nonexistent2']));
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
        $redis->method('get')->willReturn(serialize('data'));

        $adapter = new RedisAdapter($redis);

        $this->assertTrue($adapter->set('user:123:profile', 'data'));
        $this->assertEquals('data', $adapter->get('user:123:profile'));
    }

    public function testKeyPrefixing(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('set')
            ->with('cache:mykey', serialize('myvalue'))
            ->willReturn(true);

        $adapter = new RedisAdapter($redis);

        $adapter->set('mykey', 'myvalue');
    }

    public function testCustomPrefix(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('set')
            ->with('app:mykey', serialize('myvalue'))
            ->willReturn(true);

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW, 'app:');

        $adapter->set('mykey', 'myvalue');
    }

    public function testCustomPrefixInGetAndHas(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->expects($this->once())
            ->method('get')
            ->with('myapp:key')
            ->willReturn(serialize('value'));
        $redis->expects($this->once())
            ->method('exists')
            ->with('myapp:key')
            ->willReturn(1);

        $adapter = new RedisAdapter($redis, RedisAdapter::MODE_THROW, 'myapp:');

        $this->assertEquals('value', $adapter->get('key'));
        $this->assertTrue($adapter->has('key'));
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
        $redis->method('get')->willReturn(serialize('value'));

        $adapter = new RedisAdapter($redis);

        $key = 'aA0_.:-zZ9';
        $this->assertTrue($adapter->set($key, 'value'));
        $this->assertEquals('value', $adapter->get($key));
    }

    public function testUuidKey(): void
    {
        $redis = $this->createConnectedRedisMock();
        $redis->method('set')->willReturn(true);
        $redis->method('get')->willReturn(serialize('uuid_value'));

        $adapter = new RedisAdapter($redis);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertTrue($adapter->set($uuid, 'uuid_value'));
        $this->assertEquals('uuid_value', $adapter->get($uuid));
    }
}