<?php

declare(strict_types=1);

namespace Solo\Cache\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AutoloadTest extends TestCase
{
    /**
     * Compiling every type catches errors that only some builds raise, such as a typed
     * constant that 32-bit PHP cannot hold, even where the Redis extension is missing.
     */
    public function testEverySourceFileDeclaresALoadableType(): void
    {
        $root = dirname(__DIR__) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
            $type = 'Solo\\Cache\\' . str_replace('/', '\\', $relative);

            self::assertTrue(class_exists($type) || interface_exists($type), $type . ' cannot be loaded');
        }
    }
}
