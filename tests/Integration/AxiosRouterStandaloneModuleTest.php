<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Exporters\AxiosRouterExporter;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class AxiosRouterStandaloneModuleTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-axios-standalone-test';
    }

    protected static function modules(): array
    {
        return [
            ['match' => 'api/store', 'name' => 'store', 'path' => self::tmp()],
            ['match' => '*', 'name' => 'pages', 'names' => 'admin.*', 'standalone' => true, 'path' => self::tmp()],
        ];
    }

    protected static function config(): array
    {
        return ['axios' => true, 'split' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig', new class
        {
            public string $outputDirectory;
        });

        $this->app->make('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig')
            ->outputDirectory = self::tmp();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());

        parent::tearDown();
    }

    public function test_a_standalone_module_gets_no_router_and_does_not_count_as_a_second_module(): void
    {
        self::create(AxiosRouterExporter::class)->publish();

        $filesystem = new Filesystem;
        self::assertStringContainsString("from './store'", $filesystem->get(self::tmp().'/router.ts'));
        self::assertFileDoesNotExist(self::tmp().'/pages.router.ts');
        self::assertFileDoesNotExist(self::tmp().'/store.router.ts');
    }
}
