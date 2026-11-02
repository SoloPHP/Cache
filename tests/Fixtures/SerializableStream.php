<?php

declare(strict_types=1);

namespace Solo\Cache\Tests\Fixtures;

final class SerializableStream
{
    private $stream;

    public function __construct(public string $name)
    {
        $this->stream = fopen('php://memory', 'r+');
    }

    public function __serialize(): array
    {
        return ['name' => $this->name];
    }

    public function __unserialize(array $data): void
    {
        $this->__construct($data['name']);
    }

    public function __destruct()
    {
        fclose($this->stream);
    }
}
