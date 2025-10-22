<?php

declare(strict_types=1);

namespace Solo\Cache\Tests;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Solo\Cache\Adapter\CacheAdapterInterface;
use Solo\Cache\Cache;

class CacheTest extends TestCase
{
    private CacheAdapterInterface $adapter;
    private Cache $cache;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(CacheAdapterInterface::class);
        $this->cache = new Cache($this->adapter);
    }

    public function testGet(): void
    {
        $this->adapter->expects($this->once())
            ->method('get')
            ->with('key', 'default')
            ->willReturn('value');

        $result = $this->cache->get('key', 'default');
        $this->assertEquals('value', $result);
    }

    public function testSet(): void
    {
        $this->adapter->expects($this->once())
            ->method('set')
            ->with('key', 'value', 3600)
            ->willReturn(true);

        $result = $this->cache->set('key', 'value', 3600);
        $this->assertTrue($result);
    }

    public function testSetWithDateInterval(): void
    {
        $ttl = new DateInterval('PT1H');

        $this->adapter->expects($this->once())
            ->method('set')
            ->with('key', 'value', $ttl)
            ->willReturn(true);

        $result = $this->cache->set('key', 'value', $ttl);
        $this->assertTrue($result);
    }

    public function testDelete(): void
    {
        $this->adapter->expects($this->once())
            ->method('delete')
            ->with('key')
            ->willReturn(true);

        $result = $this->cache->delete('key');
        $this->assertTrue($result);
    }

    public function testClear(): void
    {
        $this->adapter->expects($this->once())
            ->method('clear')
            ->willReturn(true);

        $result = $this->cache->clear();
        $this->assertTrue($result);
    }

    public function testHas(): void
    {
        $this->adapter->expects($this->once())
            ->method('has')
            ->with('key')
            ->willReturn(true);

        $result = $this->cache->has('key');
        $this->assertTrue($result);
    }

    public function testGetMultiple(): void
    {
        $keys = ['key1', 'key2', 'key3'];
        $expected = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        $this->adapter->expects($this->once())
            ->method('getMultiple')
            ->with($keys, null)
            ->willReturn($expected);

        $result = $this->cache->getMultiple($keys);
        $this->assertEquals($expected, $result);
    }

    public function testSetMultiple(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        $this->adapter->expects($this->once())
            ->method('setMultiple')
            ->with($values, 3600)
            ->willReturn(true);

        $result = $this->cache->setMultiple($values, 3600);
        $this->assertTrue($result);
    }

    public function testDeleteMultiple(): void
    {
        $keys = ['key1', 'key2', 'key3'];

        $this->adapter->expects($this->once())
            ->method('deleteMultiple')
            ->with($keys)
            ->willReturn(true);

        $result = $this->cache->deleteMultiple($keys);
        $this->assertTrue($result);
    }
}