<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Exporters\RouterExporter;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * The route service is written to the typescript-transformer output directory while a
 * router is written next to its module, so the generated import has to bridge the two.
 */
final class RouterExporterTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-router-test';
    }

    protected static function modules(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::setUp();

        self::useTransformer(self::tmp().'/types');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     */
    private function publish(array $modules, ?string $client = 'fetch'): void
    {
        config(['stoli' => ['split' => true, 'client' => $client, 'modules' => $modules]]);
        self::forgetResolved();

        self::apply(self::create(RouterExporter::class)->generate(self::create(FileRouteBuilder::class)->files()));
    }

    public function test_it_builds_the_router_on_the_client_module_in_the_output_directory(): void
    {
        $this->publish([['name' => 'api', 'path' => self::tmp().'/routes']]);

        self::assertStringEqualsFile(self::tmp().'/routes/router.ts', GeneratedFile::HEADER."\n\n".<<<'TS'
        import { createRouter } from '../types/stoli-fetch';
        import routes from './api';

        export const Stoli = createRouter(routes);

        export default Stoli;

        TS);
    }

    public function test_several_modules_each_get_a_named_router(): void
    {
        $this->publish([
            ['match' => 'api/store', 'name' => 'store', 'path' => self::tmp()],
            ['match' => 'api/admin', 'name' => 'admin', 'path' => self::tmp()],
        ], 'axios');

        self::assertStringContainsString("from './types/stoli-axios'", (string) file_get_contents(self::tmp().'/store.router.ts'));
        self::assertFileExists(self::tmp().'/admin.router.ts');
        self::assertFileDoesNotExist(self::tmp().'/router.ts');
    }

    public function test_a_standalone_module_gets_no_router_and_does_not_count_as_a_second_module(): void
    {
        $this->publish([
            ['match' => 'api/store', 'name' => 'store', 'path' => self::tmp()],
            ['name' => 'pages', 'names' => 'admin.*', 'standalone' => true, 'path' => self::tmp()],
        ]);

        self::assertStringContainsString("from './store'", (string) file_get_contents(self::tmp().'/router.ts'));
        self::assertFileDoesNotExist(self::tmp().'/pages.router.ts');
        self::assertFileDoesNotExist(self::tmp().'/store.router.ts');
    }

    public function test_routers_no_longer_wanted_are_removed(): void
    {
        $modules = [
            ['match' => 'api/store', 'name' => 'store', 'path' => self::tmp()],
            ['match' => 'api/admin', 'name' => 'admin', 'path' => self::tmp()],
        ];

        $this->publish($modules);
        $this->publish([$modules[0]]);

        self::assertFileExists(self::tmp().'/router.ts');
        self::assertFileDoesNotExist(self::tmp().'/store.router.ts');

        $this->publish([$modules[0]], null);

        self::assertFileDoesNotExist(self::tmp().'/router.ts');
    }
}
