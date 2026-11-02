<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Internal;

use DateInterval;
use PHPUnit\Framework\TestCase;
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

    public function testInvertedIntervalIsNegative(): void
    {
        $ttl = new DateInterval('PT10S');
        $ttl->invert = 1;

        self::assertSame(-10, Ttl::seconds($ttl));
    }
}
