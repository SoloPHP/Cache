<?php

declare(strict_types=1);

namespace Solo\Cache\Internal;

use DateError;
use DateException;
use DateInterval;
use DateTimeImmutable;
use Solo\Cache\Exception\InvalidArgumentException;

/** @internal */
final class Ttl
{
    public static function seconds(null|int|DateInterval $ttl): ?int
    {
        if (!$ttl instanceof DateInterval) {
            return $ttl;
        }

        $now = new DateTimeImmutable();

        try {
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        } catch (DateError | DateException $exception) {
            throw new InvalidArgumentException('Invalid cache TTL interval', 0, $exception);
        }
    }
}
