<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;
use StubbeDev\LaravelStoli\ConstantGroupBuilder;
use StubbeDev\LaravelStoli\Exporters\ConstantsExporter;
use StubbeDev\LaravelStoli\RouteHashCache;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * End-to-end test: the exporter discovers the attributed fixture classes and
 * writes constants.ts next to the route files, skipping the write when nothing
 * changed since the last run.
 */
final class ConstantsExporterTest extends TestCase
{
    private static string $tmp = '';

    private static function tmp(): string
    {
        if (self::$tmp === '') {
            self::$tmp = sys_get_temp_dir().'/stoli-constants-test';
        }

        return self::$tmp;
    }

    private static function cache(): string
    {
        return dirname(__DIR__, 2).'/.cache';
    }

    private static function generatedFile(): string
    {
        return self::tmp().'/types/constants.ts';
    }

    protected static function modules(): array
    {
        return [
            ['match' => '*', 'name' => 'api', 'path' => self::tmp().'/routes'],
        ];
    }

    protected static function config(): array
    {
        return [
            'constants' => [
                'paths' => [dirname(__DIR__).'/Fixtures/Constants'],
                'attributes' => [TypeScriptConstants::class],
            ],
        ];
    }

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(self::cache());

        parent::setUp();

        // StoliConfig duck-types this object; the output directory is where the
        // constants file lands when the config does not override the path.
        $transformerConfig = new class
        {
            public string $outputDirectory;
        };
        $transformerConfig->outputDirectory = self::tmp().'/types';

        $this->app->instance('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig', $transformerConfig);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(self::cache());

        parent::tearDown();
    }

    public function test_it_writes_the_discovered_constants_to_the_output_directory(): void
    {
        self::create(ConstantsExporter::class)->publish();

        $this->assertFileExists(self::generatedFile());

        $content = file_get_contents(self::generatedFile());

        $this->assertStringContainsString('export const StubbeDev = {', $content);
        $this->assertStringContainsString("VIEW: 'view',", $content);
        $this->assertStringContainsString('Boundaries: {', $content);
        $this->assertStringNotContainsString('NOT_EXPORTED', $content);
        $this->assertStringContainsString(
            'export type Permission = (typeof StubbeDev.LaravelStoli.Tests.Fixtures.Constants.Permission)[keyof typeof StubbeDev.LaravelStoli.Tests.Fixtures.Constants.Permission];',
            $content
        );
    }

    public function test_an_unchanged_file_is_not_rewritten(): void
    {
        $exporter = self::create(ConstantsExporter::class);

        $exporter->publish();
        // touch() does not invalidate PHP's stat cache on every version, so the
        // pinned mtime has to be read past it.
        touch(self::generatedFile(), time() - 60);
        clearstatcache(true, self::generatedFile());
        $before = filemtime(self::generatedFile());

        $exporter->publish();

        clearstatcache(true, self::generatedFile());
        $this->assertSame($before, filemtime(self::generatedFile()));
    }

    public function test_a_hand_edited_file_is_rewritten(): void
    {
        $exporter = self::create(ConstantsExporter::class);

        $exporter->publish();
        file_put_contents(self::generatedFile(), '// touched by hand');

        $exporter->publish();

        $this->assertStringContainsString('export const StubbeDev = {', file_get_contents(self::generatedFile()));
    }

    public function test_the_generate_command_pipeline_writes_the_constants_too(): void
    {
        self::create(\StubbeDev\LaravelStoli\Publisher::class)->publish();

        $this->assertFileExists(self::generatedFile());
        $this->assertFileExists(self::tmp().'/types/stoli.js');
    }

    public function test_disabling_the_feature_writes_nothing(): void
    {
        $config = new StoliConfig([
            'constants' => [
                'enabled' => false,
                'path' => self::tmp().'/types',
                ...static::config()['constants'],
            ],
        ]);

        $exporter = new ConstantsExporter(
            new Filesystem,
            $config,
            new ConstantGroupBuilder($config),
            new RouteHashCache(new Filesystem),
        );

        $exporter->publish();

        $this->assertFileDoesNotExist(self::generatedFile());
    }
}
