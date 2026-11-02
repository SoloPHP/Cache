<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Adapter;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Solo\Cache\Adapter\ErrorMode;
use Solo\Cache\Adapter\RedisAdapter;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;

final class RedisAdapterTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is not installed');
        }
    }

    /**
     * A single value is returned on every call; several are returned in turn.
     *
     * @param list<int> $modes
     * @param list<bool> $connected
     */
    private function configureClient(Redis $redis, array $modes = [Redis::ATOMIC], array $connected = [true]): void
    {
        $redis->method('isConnected')->willReturn(...$connected);
        $redis->method('getMode')->willReturn(...$modes);
        $redis->method('getOption')->willReturnMap([
            [Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE],
            [Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE],
            [Redis::OPT_PREFIX, null],
        ]);
    }

    public function testDisconnectedClientInFailModeUsesFallbacks(): void
    {
        $adapter = new RedisAdapter(new Redis(), ErrorMode::Fail);

        self::assertSame('miss', $adapter->get('a', 'miss'));
        self::assertSame(['a' => null, 'b' => null], $adapter->getMultiple(['a', 'b']));
        self::assertFalse($adapter->has('a'));
        self::assertFalse($adapter->set('a', 1));
        self::assertFalse($adapter->delete('a'));
        self::assertFalse($adapter->clear());

        $adapter->setMode(ErrorMode::Throw);
        $this->expectException(CacheException::class);
        $adapter->get('a');
    }

    public function testDisconnectedClientIsRejectedInThrowMode(): void
    {
        $this->expectException(CacheException::class);
        new RedisAdapter(new Redis());
    }

    public function testDroppedConnectionIsLeftToTheClientToReestablish(): void
    {
        $redis = $this->createMock(Redis::class);
        $this->configureClient($redis, connected: [true, false]);
        $redis->expects(self::once())->method('get')->with('cache:a')->willReturn(serialize('value'));

        self::assertSame('value', (new RedisAdapter($redis))->get('a'));
    }

    public function testEmptyNamespaceIsRejectedEvenInFailMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RedisAdapter(new Redis(), ErrorMode::Fail, '');
    }

    public function testNativeBatchesPreserveGeneratorValuesAndNumericKeys(): void
    {
        $redis = $this->createMock(Redis::class);
        $this->configureClient($redis);
        $redis->expects(self::once())->method('mSet')->with([
            'test:12' => serialize(false),
            'test:other' => serialize(null),
        ])->willReturn(true);
        $redis->expects(self::once())->method('mGet')->with(['test:12', 'test:other'])
            ->willReturn([serialize(false), serialize(null)]);
        $redis->expects(self::once())->method('del')->with(['test:12', 'test:other'])->willReturn(0);
        $adapter = new RedisAdapter($redis, prefix: 'test');
        $keys = static function (): iterable {
            yield 0 => '12';
            yield 0 => 'other';
            yield 0 => '12';
        };

        self::assertTrue($adapter->setMultiple([12 => false, 'other' => null]));
        self::assertSame([12 => false, 'other' => null], $adapter->getMultiple($keys(), 'miss'));
        self::assertTrue($adapter->deleteMultiple($keys()));
    }

    public function testInvalidKeyDoesNotPartiallyWriteBatchWithTtl(): void
    {
        $redis = $this->createMock(Redis::class);
        $this->configureClient($redis);
        $redis->expects(self::never())->method('setex');
        $adapter = new RedisAdapter($redis, ErrorMode::Fail);

        $this->expectException(InvalidArgumentException::class);
        $adapter->setMultiple(['good' => 1, 'bad:key' => 2], 60);
    }

    public function testExpiredTtlDeletesWithoutSerializing(): void
    {
        $redis = $this->createMock(Redis::class);
        $this->configureClient($redis);
        $redis->expects(self::exactly(2))->method('del')->willReturn(1);
        $adapter = new RedisAdapter($redis);
        $unserializable = static fn(): int => 42;

        self::assertTrue($adapter->set('a', $unserializable, 0));
        self::assertTrue($adapter->setMultiple([42 => $unserializable], -1));
    }

    public function testTtlBeyondTheRedisRangeIsClamped(): void
    {
        $redis = $this->createMock(Redis::class);
        $this->configureClient($redis);
        $redis->expects(self::exactly(2))->method('setex')
            ->with(self::anything(), 9_000_000_000_000, serialize(1))
            ->willReturn(true);
        $redis->method('exec')->willReturn([true]);
        $adapter = new RedisAdapter($redis);

        self::assertTrue($adapter->set('a', 1, PHP_INT_MAX));
        self::assertTrue($adapter->setMultiple(['b' => 1], PHP_INT_MAX));
    }

    public function testBatchWithTtlIsSentAsOnePipeline(): void
    {
        $redis = $this->createMock(Redis::class);
        $this->configureClient($redis);
        $redis->expects(self::once())->method('pipeline')->willReturnSelf();
        $expected = [['cache:a', 30, serialize(1)], ['cache:b', 30, serialize(2)]];
        $redis->expects(self::exactly(2))->method('setex')->willReturnCallback(
            static function (string $key, int $ttl, string $value) use ($redis, $expected): Redis {
                self::assertContains([$key, $ttl, $value], $expected);
                return $redis;
            }
        );
        $redis->expects(self::once())->method('exec')->willReturn([true, 'OK']);
        $redis->expects(self::never())->method('discard');

        self::assertTrue((new RedisAdapter($redis))->setMultiple(['a' => 1, 'b' => 2], 30));
    }

    public function testRejectedPipelineCommandFailsTheBatch(): void
    {
        $redis = $this->createStub(Redis::class);
        $this->configureClient($redis);
        $redis->method('exec')->willReturn([true, false]);
        $adapter = new RedisAdapter($redis, ErrorMode::Fail);

        self::assertFalse($adapter->setMultiple(['a' => 1, 'b' => 2], 30));
        $adapter->setMode(ErrorMode::Throw);
        $this->expectException(CacheException::class);
        $adapter->setMultiple(['a' => 1, 'b' => 2], 30);
    }

    public function testInterruptedPipelineIsDiscarded(): void
    {
        $redis = $this->createMock(Redis::class);
        // Constructor and run() see an atomic client; the cleanup sees the abandoned pipeline.
        $this->configureClient($redis, [Redis::ATOMIC, Redis::ATOMIC, Redis::PIPELINE]);
        $redis->method('exec')->willThrowException(new RedisException('read error on connection'));
        $redis->expects(self::once())->method('discard')->willReturn(true);

        self::assertFalse((new RedisAdapter($redis, ErrorMode::Fail))->setMultiple(['a' => 1], 30));
    }

    /** @return iterable<string, array{string, Closure, mixed}> */
    public static function failedCommands(): iterable
    {
        yield 'set' => ['set', static fn($cache) => $cache->set('a', 1), false];
        yield 'setex' => ['setex', static fn($cache) => $cache->set('a', 1, 5), false];
        yield 'mset' => ['mSet', static fn($cache) => $cache->setMultiple(['a' => 1]), false];
        yield 'pipeline' => ['exec', static fn($cache) => $cache->setMultiple(['a' => 1], 5), false];
        yield 'mget' => ['mGet', static fn($cache) => $cache->getMultiple(['a'], 'miss'), ['a' => 'miss']];
        yield 'delete' => ['del', static fn($cache) => $cache->delete('a'), false];
        yield 'deleteMultiple' => ['del', static fn($cache) => $cache->deleteMultiple(['a']), false];
    }

    #[DataProvider('failedCommands')]
    public function testErrorModesHandleUnsuccessfulRedisReplies(string $command, Closure $call, mixed $fallback): void
    {
        $redis = $this->createStub(Redis::class);
        $this->configureClient($redis);
        $redis->method($command)->willReturn(false);
        $adapter = new RedisAdapter($redis, ErrorMode::Fail);

        self::assertSame($fallback, $call($adapter));
        $adapter->setMode(ErrorMode::Throw);
        $this->expectException(CacheException::class);
        $call($adapter);
    }

    public function testGetHandlesRedisExceptionAndPreservesPreviousException(): void
    {
        $redis = $this->createStub(Redis::class);
        $this->configureClient($redis);
        $error = new RedisException('connection lost');
        $redis->method('get')->willThrowException($error);
        $adapter = new RedisAdapter($redis, ErrorMode::Fail);
        self::assertSame('miss', $adapter->get('a', 'miss'));

        $adapter->setMode(ErrorMode::Throw);
        try {
            $adapter->get('a');
            self::fail('Expected cache exception');
        } catch (CacheException $exception) {
            self::assertSame($error, $exception->getPrevious());
        }
    }

    public function testClearHandlesEmptyIntermediateScanAndFailedDeletion(): void
    {
        $redis = $this->createMock(Redis::class);
        $this->configureClient($redis);
        $redis->expects(self::exactly(2))->method('scan')->willReturnCallback(static function (&$cursor) {
            if ($cursor === null) {
                $cursor = 12;
                return false;
            }

            $cursor = 0;
            return ['cache:item', 'cache:nested:item'];
        });
        $redis->expects(self::once())->method('del')->with(['cache:item'])->willReturn(false);

        $adapter = new RedisAdapter($redis, ErrorMode::Fail);
        self::assertFalse($adapter->clear());
    }
}
