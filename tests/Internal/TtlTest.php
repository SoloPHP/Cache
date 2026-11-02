<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Internal;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Solo\Cache\Exception\InvalidArgumentException;
use Solo\Cache\Internal\Ttl;

class TtlTest extends TestCase
{
    public function testIntegerAndNullTtlsArePreserved(): void
    {
        foreach ([null, 0, -1, 1, PHP_INT_MAX] as $ttl) {
            self::assertSame($ttl, Ttl::seconds($ttl));
        }
    }

    public function testIntervalIsConvertedUsingTheSameClockReading(): void
    {
        self::assertSame(3600, Ttl::seconds(new DateInterval('PT1H')));
        self::assertSame(0, Ttl::seconds(new DateInterval('PT0S')));
    }

    public function testIntervalPhpCannotApplyIsRejected(): void
    {
        $uninitialized = new class extends DateInterval {
            public function __construct()
            {
            }
        };

        $this->expectException(InvalidArgumentException::class);
        Ttl::seconds($uninitialized);
    }

    public function testInvertedIntervalIsNegative(): void
    {
        $ttl = new DateInterval('PT10S');
        $ttl->invert = 1;

        self::assertSame(-10, Ttl::seconds($ttl));
    }

    public function testIntervalEndingAfter2038IsConvertedOnEveryPlatform(): void
    {
        $interval = new DateInterval('P20Y');
        $expected = (float) (new DateTimeImmutable())->add($interval)->format('U') - time();

        self::assertEqualsWithDelta($expected, Ttl::seconds($interval), 1);
    }

    public function testIntervalBeyondDateArithmeticSaturatesInsteadOfWrapping(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('32-bit PHP truncates the fields of such an interval itself');
        }

        $past = new DateInterval('P999999999999Y');
        $past->invert = 1;

        self::assertSame(PHP_INT_MAX, Ttl::seconds(new DateInterval('P999999999999Y')));
        self::assertSame(PHP_INT_MAX, Ttl::seconds(DateInterval::createFromDateString('+999999999999 years')));
        self::assertSame(PHP_INT_MIN, Ttl::seconds($past));
    }

    public function testIntervalBeyondThe32BitRangeSaturates(): void
    {
        if (PHP_INT_SIZE >= 8) {
            self::markTestSkipped('Only 32-bit PHP reaches its integer limit within the date range');
        }

        $past = new DateInterval('P1000Y');
        $past->invert = 1;

        self::assertSame(PHP_INT_MAX, Ttl::seconds(new DateInterval('P1000Y')));
        self::assertSame(PHP_INT_MIN, Ttl::seconds($past));
    }
}
