<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Integration;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Redis;
use Solo\Cache\Adapter\ErrorMode;
use Solo\Cache\Adapter\RedisAdapter;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;

final class RedisAdapterTest extends TestCase
{
    private Redis $redis;
    private string $prefix;

    #[\Override]
    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('Set REDIS_HOST to run the Redis integration tests');
        }

        self::assertTrue(extension_loaded('redis'), 'The Redis integration tests require ext-redis');
        $this->redis = new Redis();
        self::assertTrue($this->redis->connect($host, (int) (getenv('REDIS_PORT') ?: 6379), 3.0));
        $this->prefix = 'solo-test-' . bin2hex(random_bytes(8)) . ':';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (isset($this->redis) && $this->redis->isConnected()) {
            // Every key a test writes, nested pools included, starts with the random prefix.
            $keys = $this->redis->keys($this->prefix . '*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }

            $this->redis->close();
        }
    }

    public function testStoredValuesExpirationCorruptionAndMissingKeys(): void
    {
        $cache = new RedisAdapter($this->redis, prefix: $this->prefix);
        $object = (object) ['example' => [1, 2, 3]];
        self::assertTrue($cache->setMultiple(['a' => null, 'b' => false, 20 => $object], new DateInterval('PT2M')));
        self::assertLessThanOrEqual(120, $this->redis->ttl($this->prefix . '20'));
        self::assertGreaterThan(0, $this->redis->ttl($this->prefix . '20'));
        self::assertSame(serialize($object), $this->redis->get($this->prefix . '20'));
        $this->redis->set($this->prefix . 'damaged', 'not a serialized payload');

        $values = $cache->getMultiple(['b', 'absent', '20', 'a', 'damaged'], 'miss');
        self::assertSame(false, $values['b']);
        self::assertSame('miss', $values['absent']);
        self::assertEquals($object, $values[20]);
        self::assertNull($values['a']);
        self::assertTrue($cache->has('a'));
        self::assertSame('miss', $values['damaged']);
        self::assertSame('miss', $cache->get('damaged', 'miss'));
        self::assertFalse($cache->has('damaged'));
        self::assertTrue($cache->setMultiple([20 => 0, 'b' => 0], 0));
        self::assertFalse($cache->has('20'));
        self::assertFalse($cache->has('b'));
        self::assertTrue($cache->deleteMultiple(['a', 'absent']));
    }

    public function testNeverConnectedClientDoesNotReachALocalServer(): void
    {
        if (!in_array($this->redis->getHost(), ['127.0.0.1', 'localhost'], true) || $this->redis->getPort() !== 6379) {
            self::markTestSkipped('Needs the test server on 127.0.0.1:6379, where phpredis sends a fresh client');
        }

        $this->redis->set($this->prefix . 'probe', serialize('live'));
        $cache = new RedisAdapter(new Redis(), ErrorMode::Fail, $this->prefix);

        self::assertSame('miss', $cache->get('probe', 'miss'));
        self::assertFalse($cache->set('probe', 'overwritten'));
        self::assertSame(serialize('live'), $this->redis->get($this->prefix . 'probe'));
    }

    public function testExtremeTtlIsAcceptedByTheServer(): void
    {
        $cache = new RedisAdapter($this->redis, prefix: $this->prefix);
        self::assertTrue($cache->set('forever', 'value', PHP_INT_MAX));
        self::assertGreaterThan(0, $this->redis->ttl($this->prefix . 'forever'));
        self::assertSame('value', $cache->get('forever'));
    }

    public function testInterruptedPipelineLeavesTheClientUsable(): void
    {
        $cache = new RedisAdapter($this->redis, ErrorMode::Fail, $this->prefix);
        $admin = new Redis();
        self::assertTrue($admin->connect($this->redis->getHost(), $this->redis->getPort(), 3.0));
        $this->redis->setOption(Redis::OPT_READ_TIMEOUT, 0.1);

        $admin->rawCommand('CLIENT', 'PAUSE', '10000', 'WRITE');
        try {
            self::assertFalse($cache->setMultiple(['a' => 1, 'b' => 2], 60));
            self::assertSame(Redis::ATOMIC, $this->redis->getMode());
        } finally {
            $admin->rawCommand('CLIENT', 'UNPAUSE');
        }

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2], 60));
        self::assertSame(['a' => 1, 'b' => 2], $cache->getMultiple(['a', 'b']));
        $admin->close();
    }

    public function testClearTreatsGlobCharactersLiterallyAndSkipsNestedNamespaces(): void
    {
        $prefix = $this->prefix . '*?a[bc]';
        $cache = new RedisAdapter($this->redis, prefix: $prefix);
        $neighbor = $this->prefix . 'xxab:0';
        $nested = $prefix . ':nested:0';
        $this->redis->set($neighbor, 'survivor');
        $this->redis->set($nested, 'nested');
        $items = array_fill(0, 350, 1);

        self::assertTrue($cache->setMultiple($items));
        self::assertTrue($cache->clear());
        $keys = array_map('strval', array_keys($items));
        self::assertSame(array_fill(0, 350, 'missing'), $cache->getMultiple($keys, 'missing'));
        self::assertSame('survivor', $this->redis->get($neighbor));
        self::assertSame('nested', $this->redis->get($nested));
    }

    public function testRejectsClientOptionsWithoutChangingThem(): void
    {
        $options = [Redis::OPT_PREFIX => 'client:', Redis::OPT_SERIALIZER => Redis::SERIALIZER_PHP];
        foreach ($options as $option => $value) {
            $original = $this->redis->getOption($option);
            $this->redis->setOption($option, $value);
            try {
                new RedisAdapter($this->redis, prefix: $this->prefix);
                self::fail('Incompatible client option was accepted');
            } catch (InvalidArgumentException) {
                self::assertSame($value, $this->redis->getOption($option));
            } finally {
                $this->redis->setOption($option, $original ?? '');
            }
        }

        $cache = new RedisAdapter($this->redis, prefix: $this->prefix);
        $this->redis->multi();
        try {
            $cache->set('queued', 'danger');
            self::fail('Cache command was accepted in MULTI mode');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->redis->exec());
        }
    }

    public function testServerErrorIsDistinctFromMissAndDoesNotPoisonNextRead(): void
    {
        $cache = new RedisAdapter($this->redis, prefix: $this->prefix);
        $this->redis->rPush($this->prefix . 'wrongtype', 'value');

        try {
            $cache->get('wrongtype');
            self::fail('GET on a Redis list must report a backend error');
        } catch (CacheException $error) {
            self::assertStringContainsString('WRONGTYPE', $error->getMessage());
        }

        self::assertSame('miss', $cache->get('absent', 'miss'));
    }
}
