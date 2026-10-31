<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Exporters\QueryExporter;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class QueryExporterTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-query-test';
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
    private function publish(array $modules, mixed $query = 'tanstack', ?string $client = 'fetch'): void
    {
        config(['stoli' => ['split' => true, 'client' => $client, 'query' => $query, 'modules' => $modules]]);
        self::forgetResolved();

        self::apply(self::create(QueryExporter::class)->generate(self::create(FileRouteBuilder::class)->files()));
    }

    public function test_it_builds_the_queries_on_the_router_and_the_runtime_module(): void
    {
        $this->publish([['name' => 'api', 'path' => self::tmp().'/routes']]);

        self::assertStringEqualsFile(self::tmp().'/routes/api.query.ts', GeneratedFile::HEADER."\n\n".<<<'TS'
        import { createQueries } from '../types/stoli-tanstack';
        import type { ApiRoutes } from './api';
        import { Stoli } from './router';

        export const { stoliQuery, stoliKey } = createQueries<ApiRoutes, []>(Stoli.get, []);

        TS);
    }

    public function test_several_modules_each_get_their_own_keys_on_their_own_router(): void
    {
        $this->publish([
            ['match' => 'api/store', 'name' => 'store', 'path' => self::tmp()],
            ['match' => 'api/admin', 'name' => 'admin', 'path' => self::tmp()],
        ]);

        $store = (string) file_get_contents(self::tmp().'/store.query.ts');

        self::assertStringContainsString("import { Stoli } from './store.router';", $store);
        self::assertStringContainsString("createQueries<StoreRoutes, ['store']>(Stoli.get, ['store']);", $store);
        self::assertStringContainsString("createQueries<AdminRoutes, ['admin']>(Stoli.get, ['admin']);", (string) file_get_contents(self::tmp().'/admin.query.ts'));
    }

    public function test_a_standalone_module_gets_no_queries_and_does_not_count_as_a_second_module(): void
    {
        $this->publish([
            ['match' => 'api/store', 'name' => 'store', 'path' => self::tmp()],
            ['name' => 'pages', 'names' => 'admin.*', 'standalone' => true, 'path' => self::tmp()],
        ]);

        self::assertStringContainsString('createQueries<StoreRoutes, []>(Stoli.get, []);', (string) file_get_contents(self::tmp().'/store.query.ts'));
        self::assertFileDoesNotExist(self::tmp().'/pages.query.ts');
    }

    public function test_turning_them_off_removes_the_file(): void
    {
        $modules = [['name' => 'api', 'path' => self::tmp()]];

        $this->publish($modules);
        $this->publish($modules, null);

        self::assertFileDoesNotExist(self::tmp().'/api.query.ts');
    }

    public function test_an_unknown_query_library_is_rejected(): void
    {
        $this->expectException(StoliException::class);
        $this->expectExceptionMessage("The \"query\" option must be one of 'tanstack' or null, got string");

        $this->publish([['name' => 'api', 'path' => self::tmp()]], 'swr');
    }

    public function test_queries_without_a_router_are_rejected(): void
    {
        $this->expectException(StoliException::class);
        $this->expectExceptionMessage("The \"query\" option 'tanstack' calls the router, so it needs the \"client\" option set");

        $this->publish([['name' => 'api', 'path' => self::tmp()]], client: null);
    }
}
