<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Fixtures;

final class SilencedWarningFixture
{
    public function __construct(public string $state)
    {
    }

    public function __serialize(): array
    {
        @file_get_contents(__DIR__ . '/missing-serialize-input');

        return ['state' => $this->state];
    }

    public function __unserialize(array $data): void
    {
        @file_get_contents(__DIR__ . '/missing-unserialize-input');
        trigger_error('Fixture restored', E_USER_DEPRECATED);
        $this->state = $data['state'];
    }
}
