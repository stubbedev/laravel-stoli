<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Exporters\AxiosRouterExporter;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * The route service is written to the typescript-transformer output directory while the
 * router is written next to its module, so the generated import has to bridge the two.
 */
final class AxiosRouterExporterTest extends TestCase
{
    private static string $tmp = '';

    private static function tmp(): string
    {
        if (self::$tmp === '') {
            self::$tmp = sys_get_temp_dir().'/stoli-axios-router-test';
        }

        return self::$tmp;
    }

    protected static function modules(): array
    {
        return [
            ['match' => '*', 'name' => 'api', 'path' => self::tmp().'/routes'],
        ];
    }

    protected static function config(): array
    {
        // Split mode is what puts the router in the module's own directory; in single-file
        // mode the router and the route service both land in the transformer output.
        return ['axios' => true, 'split' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::useTransformerOutputDirectory(self::tmp().'/types');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());

        parent::tearDown();
    }

    private function generated(): string
    {
        self::create(AxiosRouterExporter::class)->publish();

        return (new Filesystem)->get(self::tmp().'/routes/router.ts');
    }

    public function test_it_imports_the_route_service_from_the_transformer_output_directory(): void
    {
        self::assertStringContainsString("from '../types/stoli'", $this->generated());
    }

    public function test_it_imports_the_routes_from_its_own_directory(): void
    {
        self::assertStringContainsString("from './api'", $this->generated());
    }

    public function test_it_uses_a_plain_relative_import_when_both_land_in_the_same_directory(): void
    {
        self::useTransformerOutputDirectory(self::tmp().'/routes');

        self::assertStringContainsString("from './stoli'", $this->generated());
    }
}
