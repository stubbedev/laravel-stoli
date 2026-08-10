<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\RouteHashCache;
use StubbeDev\LaravelStoli\Support\ArrayList;

final class RouteHashCacheTest extends TestCase
{
    private function filesystem(): Filesystem
    {
        return new class extends Filesystem
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

            public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false): bool
            {
                return true;
            }
        };
    }

    private function file(string $name): File
    {
        return new File($name, 'resources/routes', new ArrayList([]));
    }

    public function test_recording_one_file_keeps_the_hashes_of_its_siblings(): void
    {
        $filesystem = $this->filesystem();
        $cache = new RouteHashCache($filesystem);

        $cache->record($this->file('store'), 'store contents');
        $cache->record($this->file('admin'), 'admin contents');

        $stored = json_decode(reset($filesystem->written), true);

        self::assertArrayHasKey('resources/routes/store', $stored, 'recording admin dropped store');
        self::assertArrayHasKey('resources/routes/admin', $stored);
    }

    public function test_unchanged_content_is_detected_once_recorded(): void
    {
        $filesystem = $this->filesystem();
        (new RouteHashCache($filesystem))->record($this->file('api'), 'contents');

        // A later run loads what the previous one persisted.
        $cache = new RouteHashCache($filesystem);
        $written = 'resources/routes/api.ts';
        $filesystem->put($written, 'contents');

        self::assertTrue($cache->isUnchanged($this->file('api'), 'contents', $written));
        self::assertFalse($cache->isUnchanged($this->file('api'), 'other contents', $written));
    }

    public function test_content_is_changed_when_the_output_file_is_gone(): void
    {
        $filesystem = $this->filesystem();
        (new RouteHashCache($filesystem))->record($this->file('api'), 'contents');

        $cache = new RouteHashCache($filesystem);

        self::assertFalse($cache->isUnchanged($this->file('api'), 'contents', 'resources/routes/api.ts'));
    }
}
