<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use StubbeDev\LaravelStoli\Compilers\TypeScript;
use StubbeDev\LaravelStoli\Exporters\UrlsExporter;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class UrlsExporterTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-urls-test';
    }

    protected static function modules(): array
    {
        return [['match' => '*', 'name' => 'api', 'path' => self::tmp(), 'rootUrl' => 'https://app.test']];
    }

    protected static function config(): array
    {
        return ['split' => true, 'urls' => true];
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('users/{user}', static fn () => [])->name('users.show');
        $router->get('users-show', static fn () => [])->name('users-show');
        $router->delete('items', static fn () => [])->name('delete');
        $router->get('missing', static fn () => [])->name('404');
    }

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::tearDown();
    }

    private function publish(): string
    {
        self::forgetResolved();
        self::apply(self::create(UrlsExporter::class)->generate(self::create(FileRouteBuilder::class)->files()));

        return (string) @file_get_contents(self::tmp().'/api.urls.ts');
    }

    public function test_each_route_gets_a_function_named_after_it(): void
    {
        $output = $this->publish();

        self::assertStringContainsString("import { url, type Arguments } from './stoli';\nimport type { ApiRouteParams } from './api';", $output);
        self::assertStringContainsString(<<<'TS'
        /** GET users/{user} */
        export const usersShow = (...[parameters]: Arguments<ApiRouteParams['users.show']>): string =>
        	url('users.show', {
        		host: 'https://app.test',
        		uri: 'users/{user}',
        	}, parameters);
        TS, $output);
    }

    public function test_names_that_collide_are_numbered_and_reserved_ones_made_a_routes(): void
    {
        $output = $this->publish();

        self::assertStringContainsString('export const usersShow2 = ', $output);
        self::assertStringContainsString('export const deleteRoute = ', $output);
        self::assertStringContainsString('export const route404 = ', $output);
    }

    public function test_turning_them_off_removes_the_file(): void
    {
        $this->publish();
        config(['stoli.urls' => false]);

        self::assertSame('', $this->publish());
    }

    public function test_identifiers(): void
    {
        self::assertSame('adminProductsList', TypeScript::identifier('admin.products-list'));
        self::assertSame('apiV2UsersShow', TypeScript::identifier('api.v2.users_show'));
        self::assertSame('classRoute', TypeScript::identifier('class'));
        self::assertSame('route', TypeScript::identifier('...'));
    }
}
