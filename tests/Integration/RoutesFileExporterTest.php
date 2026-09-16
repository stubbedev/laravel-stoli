<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Exporters\RoutesFileExporter;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * End-to-end test: the exporter writes real files next to the module and the
 * hash cache decides whether each run actually writes or skips.
 */
final class RoutesFileExporterTest extends TestCase
{
    private static string $tmp = '';

    private static function tmp(): string
    {
        if (self::$tmp === '') {
            self::$tmp = sys_get_temp_dir().'/stoli-routes-file-test';
        }

        return self::$tmp;
    }

    private static function cache(): string
    {
        return dirname(__DIR__, 2).'/.cache';
    }

    protected static function modules(): array
    {
        return [
            ['match' => '*', 'name' => 'api', 'path' => self::tmp().'/routes'],
        ];
    }

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::$tmp);
        (new Filesystem)->delete(self::cache());

        parent::setUp();

        // StoliConfig duck-types this object; its output directory is where the single
        // generated file lands, so a stand-in with the one property is enough.
        $transformerConfig = new class
        {
            public string $outputDirectory;
        };
        $transformerConfig->outputDirectory = self::tmp().'/types';

        $this->app->instance('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig', $transformerConfig);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::$tmp);
        (new Filesystem)->delete(self::cache());

        parent::tearDown();
    }

    private function generated(): string
    {
        $path = self::tmp().'/types/api.ts';

        self::create(RoutesFileExporter::class)->publish();

        return (new Filesystem)->get($path);
    }

    public function test_it_writes_the_compiled_routes_file(): void
    {
        $content = $this->generated();

        self::assertStringContainsString("'store.products.list'", $content);
        self::assertStringContainsString("'store.cart.add'", $content);
        self::assertStringContainsString("'admin.users.update'", $content);
    }

    public function test_it_skips_writing_when_the_output_file_is_up_to_date(): void
    {
        $content = $this->generated();
        $path = self::tmp().'/types/api.ts';

        // A write bumps mtime, so pinning it lets the test observe the skip.
        touch($path, 100);

        self::create(RoutesFileExporter::class)->publish();

        self::assertSame(100, filemtime($path));
        self::assertSame($content, (new Filesystem)->get($path));
    }

    public function test_it_restores_an_output_file_changed_outside_the_generator(): void
    {
        $path = self::tmp().'/types/api.ts';
        $content = $this->generated();

        // A merge or git checkout restores an older committed version of the file.
        (new Filesystem)->put($path, '// stale committed version');

        self::create(RoutesFileExporter::class)->publish();

        self::assertSame($content, (new Filesystem)->get($path));
    }
}
