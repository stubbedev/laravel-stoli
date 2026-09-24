<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Exporters\AxiosRouterExporter;
use StubbeDev\LaravelStoli\Exporters\RouteServiceExporter;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * The route service and the axios router are copied from stubs; like the route files
 * they are only rewritten when they no longer hold what the stub produces.
 */
final class RouteServiceExporterTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-route-service-test';
    }

    private static function cache(): string
    {
        return dirname(__DIR__, 2).'/.cache';
    }

    protected static function modules(): array
    {
        return [['match' => '*', 'name' => 'api']];
    }

    protected static function config(): array
    {
        return ['axios' => true];
    }

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(self::cache());

        parent::setUp();

        self::useTransformerOutputDirectory(self::tmp());
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(self::cache());

        parent::tearDown();
    }

    private function publish(): void
    {
        self::create(RouteServiceExporter::class)->publish();
        self::create(AxiosRouterExporter::class)->publish();
    }

    public function test_unchanged_files_are_not_rewritten(): void
    {
        $this->publish();

        $files = array_map(static fn (string $file): string => self::tmp()."/{$file}", ['stoli.js', 'stoli.d.ts', 'router.ts']);

        foreach ($files as $file) {
            touch($file, 100);
            clearstatcache(true, $file);
        }

        $this->publish();

        foreach ($files as $file) {
            clearstatcache(true, $file);
            self::assertSame(100, filemtime($file), basename($file).' was rewritten');
        }
    }

    public function test_a_file_changed_outside_the_generator_is_restored(): void
    {
        $this->publish();

        (new Filesystem)->put(self::tmp().'/stoli.js', '// edited');

        $this->publish();

        self::assertFileEquals(dirname(__DIR__, 2).'/resources/stoli.stub', self::tmp().'/stoli.js');
    }
}
