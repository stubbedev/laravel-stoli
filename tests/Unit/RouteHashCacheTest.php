<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\RouteHashCache;

final class RouteHashCacheTest extends TestCase
{
    private function filesystem(): InMemoryFilesystem
    {
        return new InMemoryFilesystem;
    }

    public function test_recording_one_file_keeps_the_hashes_of_its_siblings(): void
    {
        $filesystem = $this->filesystem();
        $cache = new RouteHashCache($filesystem);

        $cache->record('resources/routes/store.ts', 'store contents');
        $cache->record('resources/routes/admin.ts', 'admin contents');

        $stored = json_decode((string) reset($filesystem->written), true);

        self::assertIsArray($stored);
        self::assertArrayHasKey('resources/routes/store.ts', $stored, 'recording admin dropped store');
        self::assertArrayHasKey('resources/routes/admin.ts', $stored);
    }

    public function test_unchanged_content_is_detected_once_recorded(): void
    {
        $filesystem = $this->filesystem();
        $written = 'resources/routes/api.ts';
        $filesystem->put($written, 'contents');
        (new RouteHashCache($filesystem))->record($written, 'contents');

        // A later run loads what the previous one persisted.
        $cache = new RouteHashCache($filesystem);

        self::assertTrue($cache->isUnchanged($written, 'contents'));
        self::assertFalse($cache->isUnchanged($written, 'other contents'));
    }

    public function test_content_is_changed_when_the_output_file_is_gone(): void
    {
        $filesystem = $this->filesystem();
        (new RouteHashCache($filesystem))->record('resources/routes/api.ts', 'contents');

        $cache = new RouteHashCache($filesystem);

        self::assertFalse($cache->isUnchanged('resources/routes/api.ts', 'contents'));
    }

    public function test_content_is_changed_when_the_output_file_was_changed_elsewhere(): void
    {
        $filesystem = $this->filesystem();
        $written = 'resources/routes/api.ts';
        $filesystem->put($written, 'contents');
        (new RouteHashCache($filesystem))->record($written, 'contents');

        // A git checkout or hand edit replaces the generated file behind the generator's back.
        $cache = new RouteHashCache($filesystem);
        $filesystem->put($written, 'older committed contents');

        self::assertFalse($cache->isUnchanged($written, 'contents'));
    }

    public function test_unchanged_detection_survives_a_formatter_rewriting_the_output(): void
    {
        $filesystem = $this->filesystem();
        $written = 'resources/routes/api.ts';
        $filesystem->put($written, 'formatted contents');
        (new RouteHashCache($filesystem))->record($written, 'raw contents');

        // The formatter re-formatted what was written; the next run still skips it.
        $cache = new RouteHashCache($filesystem);
        self::assertTrue($cache->isUnchanged($written, 'raw contents'));

        // Unless the formatted file is replaced by something else.
        $filesystem->put($written, 'reverted contents');
        self::assertFalse($cache->isUnchanged($written, 'raw contents'));
    }

    public function test_a_hash_recorded_by_an_older_cache_format_is_treated_as_stale(): void
    {
        $filesystem = $this->filesystem();
        (new RouteHashCache($filesystem))->record('resources/routes/api.ts', 'contents');

        $cachePath = (string) array_key_first($filesystem->written);
        $filesystem->put($cachePath, (string) json_encode(['resources/routes/api.ts' => hash('sha256', 'contents')]));
        $filesystem->put('resources/routes/api.ts', 'contents');

        $cache = new RouteHashCache($filesystem);

        self::assertFalse($cache->isUnchanged('resources/routes/api.ts', 'contents'));
    }
}
