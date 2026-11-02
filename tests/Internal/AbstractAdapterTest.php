<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Internal;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Solo\Cache\Tests\Fixtures\RecordingAdapter;
use Solo\Cache\Adapter\ErrorMode;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Exception\InvalidArgumentException;

class AbstractAdapterTest extends TestCase
{
    public function testInvalidBatchIsValidatedBeforeAnyMutation(): void
    {
        $adapter = new RecordingAdapter();

        $batches = ['setMultiple' => ['good' => 1, 'bad:key' => 2], 'deleteMultiple' => ['good', 'bad:key']];
        foreach ($batches as $method => $values) {
            try {
                $adapter->$method($values);
                self::fail('Invalid batch accepted');
            } catch (InvalidArgumentException) {
                self::assertSame([], $adapter->calls);
            }
        }
    }

    public function testFailedBatchOperationsDoNotSkipRemainingKeys(): void
    {
        $adapter = new RecordingAdapter();
        $adapter->succeed = false;

        self::assertFalse($adapter->setMultiple(['first' => 1, 'second' => 2]));
        self::assertSame(['first', 'second'], array_column($adapter->calls, 0));

        $adapter->calls = [];
        self::assertFalse($adapter->deleteMultiple(['first', 'second']));
        self::assertSame(['first', 'second'], array_column($adapter->calls, 0));
    }

    public function testNumericKeysAndIntervalsReachSingleItemOperations(): void
    {
        $adapter = new RecordingAdapter();
        self::assertTrue($adapter->setMultiple(['123' => 'value'], new DateInterval('PT10S')));
        self::assertSame([['123', 'value', 10]], $adapter->calls);
    }

    public function testDefaultGetMultiplePreservesGeneratorValues(): void
    {
        $keys = (static function (): \Generator {
            yield 0 => 'first';
            yield 0 => 'second';
        })();

        $result = (new RecordingAdapter())->getMultiple($keys, 'missing');
        self::assertSame(['first' => 'missing', 'second' => 'missing'], $result);
    }

    public function testFailurePolicyCanBeChangedAtRuntime(): void
    {
        $adapter = new RecordingAdapter(ErrorMode::Fail);
        self::assertFalse($adapter->reportFailure());
        $adapter->setMode(ErrorMode::Throw);

        $this->expectException(CacheException::class);
        $adapter->reportFailure();
    }
}
