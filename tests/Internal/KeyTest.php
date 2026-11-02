<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Cache\Exception\InvalidArgumentException;
use Solo\Cache\Internal\Key;

class KeyTest extends TestCase
{
    public function testAllRequiredCharactersAndLongKeysAreSupported(): void
    {
        $keys = ['0', 'aA0_.-zZ9', str_repeat('a', 128)];
        self::assertSame($keys, Key::keys($keys));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        foreach (['', 'a:b', 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', "key\n", 'a b'] as $key) {
            yield var_export($key, true) => [$key];
        }
    }

    #[DataProvider('invalidKeys')]
    public function testReservedCharactersAndTrailingNewlinesAreRejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        Key::validate($key);
    }

    public function testGeneratorIndexesCannotOverwriteKeys(): void
    {
        $keys = (static function (): \Generator {
            yield 0 => 'first';
            yield 0 => 'second';
            yield 0 => 'first';
        })();

        self::assertSame(['first', 'second'], Key::keys($keys));
    }

    public function testDeduplicationCannotHideInvalidKeyTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Key::keys(['1', 1]);
    }

    public function testNumericStringArrayKeysAreSupported(): void
    {
        self::assertSame([123 => 'value'], Key::values(['123' => 'value']));
    }

    public function testGeneratorStringKeysArePreservedAndLastDuplicateWins(): void
    {
        $values = (static function (): \Generator {
            yield 'first' => 1;
            yield 'second' => 2;
            yield 'first' => 3;
        })();

        self::assertSame(['first' => 3, 'second' => 2], Key::values($values));
    }

    public function testInvalidGeneratorKeyTypeIsRejected(): void
    {
        $values = (static function (): \Generator {
            yield new \stdClass() => 'value';
        })();

        $this->expectException(InvalidArgumentException::class);
        Key::values($values);
    }
}
