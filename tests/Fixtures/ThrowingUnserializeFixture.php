<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Fixtures;

use RuntimeException;

final class ThrowingUnserializeFixture
{
    public function __unserialize(array $data): void
    {
        throw new RuntimeException('Fixture cannot be restored');
    }
}
