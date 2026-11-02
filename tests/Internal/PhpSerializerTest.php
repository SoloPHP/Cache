<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Internal;

use PHPUnit\Framework\TestCase;
use Solo\Cache\Tests\Fixtures\SerializableStream;
use Solo\Cache\Tests\Fixtures\SilencedWarningFixture;
use Solo\Cache\Tests\Fixtures\ThrowingUnserializeFixture;
use Solo\Cache\Exception\CacheException;
use Solo\Cache\Internal\PhpSerializer;

class PhpSerializerTest extends TestCase
{
    public function testScalarAndNestedValuesRoundTripWithoutTypeChanges(): void
    {
        foreach ([null, false, true, 0, 1.25, '', "binary\0value", [false, null, ['one' => 1]]] as $value) {
            self::assertSame($value, PhpSerializer::decode(PhpSerializer::encode($value), 'missing'));
        }

        $object = (object) ['foo' => ['bar' => null]];
        self::assertEquals($object, PhpSerializer::decode(PhpSerializer::encode($object)));
    }

    public function testMalformedDataIsAMissInsteadOfAFalseValueOrWarning(): void
    {
        foreach (['', 'broken', 'b:2;', 'a:1:{', 's:10:"short";'] as $payload) {
            self::assertSame('missing', PhpSerializer::decode($payload, 'missing'));
        }
    }

    public function testUnavailableClassesAreMissesAtAnyDepth(): void
    {
        $unknown = 'O:28:"SoloCacheMissingFixtureClass":0:{}';
        self::assertSame('missing', PhpSerializer::decode($unknown, 'missing'));
        self::assertSame('missing', PhpSerializer::decode('a:1:{i:0;' . $unknown . '}', 'missing'));
    }

    public function testResourcesAreRejectedAtAnyDepth(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);

        try {
            foreach ([$resource, [$resource], (object) ['nested' => [$resource]]] as $value) {
                try {
                    PhpSerializer::encode($value);
                    self::fail('Resource was serialized as an integer');
                } catch (CacheException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            fclose($resource);
        }
    }

    public function testObjectCanChooseItsOwnSerializedState(): void
    {
        $value = new SerializableStream('report');
        $decoded = PhpSerializer::decode(PhpSerializer::encode($value));
        self::assertInstanceOf(SerializableStream::class, $decoded);
        self::assertSame('report', $decoded->name);
    }

    public function testRecursiveGraphsCanRoundTrip(): void
    {
        $array = ['name' => 'array'];
        $array['self'] = &$array;
        $object = (object) ['name' => 'object'];
        $object->self = $object;
        $object->array = &$array;
        $array['object'] = $object;

        $result = PhpSerializer::decode(PhpSerializer::encode($array));

        self::assertIsArray($result);
        self::assertSame('array', $result['self']['self']['name']);
        self::assertSame($result['object'], $result['object']->self);
        self::assertSame('array', $result['object']->array['name']);
    }

    public function testUnsupportedValuesInsideRecursiveGraphsAreRejected(): void
    {
        $resource = fopen('php://memory', 'r+');
        $array = [];
        $array['self'] = &$array;
        $array['resource'] = $resource;

        try {
            $this->expectException(CacheException::class);
            PhpSerializer::encode($array);
        } finally {
            fclose($resource);
        }
    }

    public function testSerializationErrorsAreWrapped(): void
    {
        try {
            PhpSerializer::encode(static fn (): bool => true);
            self::fail('Closure should not serialize');
        } catch (CacheException $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testThrowingUnserializeHookIsAMiss(): void
    {
        self::assertSame(
            'missing',
            PhpSerializer::decode(serialize(new ThrowingUnserializeFixture()), 'missing')
        );
    }

    public function testCallerSilenceCannotHideSerializerWarnings(): void
    {
        self::assertSame('missing', @PhpSerializer::decode('i:1;trailing', 'missing'));

        $level = error_reporting(0);
        try {
            self::assertSame('missing', PhpSerializer::decode('i:1;trailing', 'missing'));
        } finally {
            error_reporting($level);
        }
    }

    public function testSilencedHookWarningsAreIgnoredAndOtherErrorsReachThePreviousHandler(): void
    {
        $errors = [];
        set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
            $errors[] = [$severity, $message];
            return true;
        });

        try {
            $decoded = PhpSerializer::decode(PhpSerializer::encode(new SilencedWarningFixture('kept')), 'missing');
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(SilencedWarningFixture::class, $decoded);
        self::assertSame('kept', $decoded->state);
        self::assertContains([E_USER_DEPRECATED, 'Fixture restored'], $errors);
    }

    public function testCallerErrorHandlerIsRestoredAfterFailure(): void
    {
        $handler = static fn (): bool => false;
        set_error_handler($handler);

        try {
            PhpSerializer::decode('broken');
            $restored = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            self::assertSame($handler, $restored);
        } finally {
            restore_error_handler();
        }
    }
}
