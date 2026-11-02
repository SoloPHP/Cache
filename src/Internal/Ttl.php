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
    /** Seconds (about 126 billion years) beyond which PHP's date arithmetic wraps around without an error. */
    private const float OVERFLOW = 4.0e18;

    public static function seconds(null|int|DateInterval $ttl): ?int
    {
        if (!$ttl instanceof DateInterval) {
            return $ttl;
        }

        $now = new DateTimeImmutable();

        try {
            $then = $now->add($ttl);
        } catch (DateError | DateException $exception) {
            throw new InvalidArgumentException('Invalid cache TTL interval', 0, $exception);
        }

        // Saturate instead of wrapping: such a TTL means "practically never" or "already expired".
        $estimate = self::estimate($ttl);
        if (abs($estimate) >= self::OVERFLOW) {
            return $estimate > 0 ? PHP_INT_MAX : PHP_INT_MIN;
        }

        // Read as text, a timestamp after 2038 also works on 32-bit builds.
        return self::saturate((float) $then->format('U') - (float) $now->format('U'));
    }

    /** Length from the interval's fields, close enough to tell whether it can be computed exactly. */
    private static function estimate(DateInterval $ttl): float
    {
        $days = is_int($ttl->days) ? $ttl->days : $ttl->y * 365.2425 + $ttl->m * 30.436875 + $ttl->d;
        $seconds = $days * 86400.0 + $ttl->h * 3600.0 + $ttl->i * 60.0 + $ttl->s;

        return $ttl->invert === 1 ? -$seconds : $seconds;
    }

    private static function saturate(float $seconds): int
    {
        return match (true) {
            $seconds >= PHP_INT_MAX => PHP_INT_MAX,
            $seconds <= PHP_INT_MIN => PHP_INT_MIN,
            default => (int) $seconds,
        };
    }
}
