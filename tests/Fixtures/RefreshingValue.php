<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Fixtures;

use RuntimeException;
use Solo\Cache\Adapter\FileAdapter;

final readonly class RefreshingValue
{
    public function __construct(private string $directory)
    {
    }

    public function __wakeup(): void
    {
        // Fail promptly if GC regresses and invokes this hook under the mutation lock.
        $lock = fopen($this->directory . '/.solo-cache.lock', 'c+b');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Deserialization ran while the cache was locked');
            }
        } finally {
            fclose($lock);
        }

        (new FileAdapter($this->directory))->set('refresh', 'fresh');
    }
}
