<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Fixtures;

use DateInterval;
use Solo\Cache\Adapter\AbstractAdapter;

final class RecordingAdapter extends AbstractAdapter
{
    public array $calls = [];
    public bool $succeed = true;

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->calls[] = [$key, $value, $ttl];
        return $this->succeed;
    }

    public function delete(string $key): bool
    {
        $this->calls[] = [$key];
        return $this->succeed;
    }

    public function clear(): bool
    {
        return $this->succeed;
    }

    public function reportFailure(): bool
    {
        return $this->fail('Failure fixture');
    }
}
