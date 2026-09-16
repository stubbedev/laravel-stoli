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

        $cache->record($this->file('store'), 'store contents', 'resources/routes/store.ts');
        $cache->record($this->file('admin'), 'admin contents', 'resources/routes/admin.ts');

        $stored = json_decode(reset($filesystem->written), true);

        self::assertArrayHasKey('resources/routes/store', $stored, 'recording admin dropped store');
        self::assertArrayHasKey('resources/routes/admin', $stored);
    }

    public function test_unchanged_content_is_detected_once_recorded(): void
    {
        $filesystem = $this->filesystem();
        $written = 'resources/routes/api.ts';
        $filesystem->put($written, 'contents');
        (new RouteHashCache($filesystem))->record($this->file('api'), 'contents', $written);

        // A later run loads what the previous one persisted.
        $cache = new RouteHashCache($filesystem);

        self::assertTrue($cache->isUnchanged($this->file('api'), 'contents', $written));
        self::assertFalse($cache->isUnchanged($this->file('api'), 'other contents', $written));
    }

    public function test_content_is_changed_when_the_output_file_is_gone(): void
    {
        $filesystem = $this->filesystem();
        (new RouteHashCache($filesystem))->record($this->file('api'), 'contents', 'resources/routes/api.ts');

        $cache = new RouteHashCache($filesystem);

        self::assertFalse($cache->isUnchanged($this->file('api'), 'contents', 'resources/routes/api.ts'));
    }

    public function test_content_is_changed_when_the_output_file_was_changed_elsewhere(): void
    {
        $filesystem = $this->filesystem();
        $written = 'resources/routes/api.ts';
        $filesystem->put($written, 'contents');
        (new RouteHashCache($filesystem))->record($this->file('api'), 'contents', $written);

        // A git checkout or hand edit replaces the generated file behind the generator's back.
        $cache = new RouteHashCache($filesystem);
        $filesystem->put($written, 'older committed contents');

        self::assertFalse($cache->isUnchanged($this->file('api'), 'contents', $written));
    }

    public function test_unchanged_detection_survives_a_formatter_rewriting_the_output(): void
    {
        $filesystem = $this->filesystem();
        $written = 'resources/routes/api.ts';
        $filesystem->put($written, 'formatted contents');
        (new RouteHashCache($filesystem))->record($this->file('api'), 'raw contents', $written);

        // The formatter re-formatted what was written; the next run still skips it.
        $cache = new RouteHashCache($filesystem);
        self::assertTrue($cache->isUnchanged($this->file('api'), 'raw contents', $written));

        // Unless the formatted file is replaced by something else.
        $filesystem->put($written, 'reverted contents');
        self::assertFalse($cache->isUnchanged($this->file('api'), 'raw contents', $written));
    }

    public function test_a_hash_recorded_by_an_older_cache_format_is_treated_as_stale(): void
    {
        $filesystem = $this->filesystem();
        (new RouteHashCache($filesystem))->record($this->file('api'), 'contents', 'resources/routes/api.ts');

        $cachePath = (string) array_key_first($filesystem->written);
        $filesystem->put($cachePath, (string) json_encode(['resources/routes/api' => hash('sha256', 'contents')]));
        $filesystem->put('resources/routes/api.ts', 'contents');

        $cache = new RouteHashCache($filesystem);

        self::assertFalse($cache->isUnchanged($this->file('api'), 'contents', 'resources/routes/api.ts'));
    }
}
