<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use Illuminate\Filesystem\Filesystem;

/**
 * Keeps written files in memory, so the hash cache can be tested without touching disk.
 */
final class InMemoryFilesystem extends Filesystem
{
    /** @var array<string, string> */
    public array $written = [];

    public function exists($path): bool
    {
        return isset($this->written[$path]);
    }

    public function get($path, $lock = false): string
    {
        return $this->written[$path] ?? '';
    }

    public function put($path, $contents, $lock = false): int
    {
        $this->written[$path] = $contents;

        return strlen($contents);
    }

    public function ensureDirectoryExists($path, $mode = 0755, $recursive = true): void {}
}
