<?php

declare(strict_types=1);

namespace Solo\Cache\Internal;

use ErrorException;
use ReflectionReference;
use Serializable;
use Solo\Cache\Exception\CacheException;
use Throwable;
use WeakMap;

/** @internal */
final class PhpSerializer
{
    private const int UNSILENCEABLE_LEVELS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR
        | E_USER_ERROR | E_RECOVERABLE_ERROR;

    public static function encode(mixed $value): string
    {
        self::captureWarnings();

        try {
            if (self::containsUnsupportedValue($value, encoding: true)) {
                throw new \UnexpectedValueException('Cache values must be serializable');
            }

            return serialize($value);
        } catch (Throwable $exception) {
            throw new CacheException('Failed to serialize cache value', 0, $exception);
        } finally {
            restore_error_handler();
        }
    }

    public static function decode(string $payload, mixed $default = null): mixed
    {
        self::captureWarnings();

        try {
            $value = unserialize($payload);
            if ($value === false && $payload !== 'b:0;') {
                return $default;
            }

            // Only object tokens can produce incomplete classes.
            if (
                (str_contains($payload, 'O:') || str_contains($payload, 'C:'))
                && self::containsUnsupportedValue($value, encoding: false)
            ) {
                return $default;
            }

            return $value;
        } catch (Throwable) {
            return $default;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Walk references without changing the caller's arrays or looping over cycles.
     *
     * @param array<string, true> $references
     * @param WeakMap<object, mixed> $objects
     */
    private static function containsUnsupportedValue(
        mixed $value,
        bool $encoding,
        array &$references = [],
        WeakMap $objects = new WeakMap()
    ): bool {
        if (
            $value instanceof \__PHP_Incomplete_Class
            || ($encoding && (is_resource($value) || gettype($value) === 'resource (closed)'))
        ) {
            return true;
        }

        if (is_object($value)) {
            // A custom serializer chooses which properties belong in its payload.
            if (
                $encoding && ($value instanceof Serializable
                    || method_exists($value, '__serialize')
                    || method_exists($value, '__sleep'))
            ) {
                return false;
            }

            if (isset($objects[$value])) {
                return false;
            }

            $objects[$value] = true;
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $element) {
            if ($element === null || is_scalar($element)) {
                continue;
            }

            $reference = is_array($element) ? ReflectionReference::fromArrayElement($value, $key) : null;
            if ($reference !== null) {
                $id = $reference->getId();
                if (isset($references[$id])) {
                    continue;
                }
                $references[$id] = true;
            }

            if (self::containsUnsupportedValue($element, $encoding, $references, $objects)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Warnings become exceptions; since PHP 8.3 the serializer reports every problem as one.
     * Hook code may silence its own warnings with @. Everything else goes to the previous handler.
     */
    private static function captureWarnings(): void
    {
        /** @var (callable(int, string, string, int): mixed)|null $previous */
        $previous = null;
        $previous = set_error_handler(
            static function (int $severity, string $message, string $file, int $line) use (&$previous): bool {
                // serialize() and unserialize() report from this file, so a caller's @ or
                // error_reporting(0) cannot hide their warnings. Inside @, error_reporting()
                // keeps only the levels it cannot silence.
                $silenced = $file !== __FILE__ && (error_reporting() & ~self::UNSILENCEABLE_LEVELS) === 0;
                if (!$silenced && $severity === E_WARNING) {
                    throw new ErrorException($message, 0, $severity, $file, $line);
                }

                return $previous !== null && $previous($severity, $message, $file, $line) !== false;
            }
        );
    }
}
