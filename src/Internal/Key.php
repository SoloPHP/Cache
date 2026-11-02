<?php

declare(strict_types=1);

namespace Solo\Cache\Internal;

use Solo\Cache\Exception\InvalidArgumentException;

/** @internal */
final class Key
{
    public static function isValid(string $key): bool
    {
        return preg_match('/\A[A-Za-z0-9_.-]+\z/', $key) === 1;
    }

    public static function validate(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }

        if (!self::isValid($key)) {
            throw new InvalidArgumentException(
                'Cache keys may only contain letters, digits, underscores, dots, and hyphens'
            );
        }
    }

    /**
     * @param iterable<mixed> $keys
     * @return list<string>
     */
    public static function keys(iterable $keys): array
    {
        $unique = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string');
            }

            self::validate($key);
            $unique[$key] = $key;
        }

        return array_values($unique);
    }

    /**
     * Integer indexes represent numeric string keys, which PHP coerces in arrays.
     *
     * @param iterable<mixed, mixed> $values
     * @return array<int|string, mixed>
     */
    public static function values(iterable $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                throw new InvalidArgumentException('Cache key must be a string or an integer array index');
            }

            $key = (string) $key;
            self::validate($key);
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
