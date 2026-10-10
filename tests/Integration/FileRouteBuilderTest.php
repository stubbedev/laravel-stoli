<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Parameter;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class FileRouteBuilderTest extends TestCase
{
    private static function outputDirectory(): string
    {
        return sys_get_temp_dir().'/stoli-file-route-builder';
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::outputDirectory());

        parent::tearDown();
    }

    protected static function modules(): array
    {
        return [];
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('api/users/{user}/posts/{post?}', static fn () => [])->whereNumber('user')->name('users.posts');
        $router->domain('{account}.app.test')->get('dashboard', static fn () => [])->name('tenant.dashboard');
        $router->get('unnamed', static fn () => []);
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @param  array<string, mixed>  $config
     * @return list<File>
     */
    private function files(array $modules, array $config = []): array
    {
        config(['stoli' => ['split' => true, 'modules' => $modules, ...$config]]);
        self::forgetResolved();

        return self::create(FileRouteBuilder::class)->files();
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @param  array<string, mixed>  $config
     * @return array<string, list<string>> file name => its route names
     */
    private function names(array $modules, array $config = []): array
    {
        $names = [];

        foreach ($this->files($modules, $config) as $file) {
            $names[$file->name] = array_map(static fn (Route $route): string => $route->name, $file->routes);
        }

        return $names;
    }

    private function route(string $name): Route
    {
        foreach ($this->files([['name' => 'api', 'path' => 'routes', 'rootUrl' => 'https://app.test']])[0]->routes as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        self::fail("No route {$name}");
    }

    public function test_each_module_takes_the_named_routes_under_its_path(): void
    {
        $names = $this->names([
            ['match' => 'api/store', 'name' => 'store', 'path' => 'routes'],
            ['match' => 'api/admin', 'name' => 'admin', 'path' => 'routes'],
        ]);

        self::assertSame(['store.products.list', 'store.cart.show', 'store.cart.add', 'store.cart.remove'], $names['store']);
        self::assertSame(['admin.products.create', 'admin.products.update', 'admin.products.show', 'admin.users.list', 'admin.users.create', 'admin.users.update'], $names['admin']);
    }

    public function test_unnamed_routes_are_left_out(): void
    {
        self::assertNotContains('', $this->names([['name' => 'api', 'path' => 'routes']])['api']);
    }

    public function test_a_names_filter_selects_routes_across_paths(): void
    {
        $names = $this->names([
            ['name' => 'store', 'names' => 'store.*', 'path' => 'routes'],
            ['name' => 'users', 'names' => ['admin.users.list', 'admin.users.update'], 'path' => 'routes'],
            ['name' => 'nothing', 'names' => 'missing.*', 'path' => 'routes'],
        ]);

        self::assertSame(['store.products.list', 'store.cart.show', 'store.cart.add', 'store.cart.remove'], $names['store']);
        self::assertSame(['admin.users.list', 'admin.users.update'], $names['users']);
        self::assertSame([], $names['nothing']);
    }

    public function test_the_strip_prefix_comes_off_the_route_names(): void
    {
        self::assertSame(
            ['products.list', 'cart.show', 'cart.add', 'cart.remove'],
            $this->names([['match' => 'api/store', 'name' => 'store', 'path' => 'routes', 'stripPrefix' => 'store.']])['store'],
        );
    }

    public function test_a_module_without_a_directory_has_no_file(): void
    {
        self::assertSame([], $this->files([['name' => 'api']]));
    }

    public function test_without_split_the_modules_are_combined_into_the_single_file(): void
    {
        self::useTransformer(self::outputDirectory());

        $files = $this->files([
            ['match' => 'api/store', 'name' => 'store'],
            ['match' => 'api/admin', 'name' => 'admin', 'names' => 'admin.users.*'],
            ['name' => 'pages', 'names' => 'tenant.*', 'standalone' => true, 'path' => 'pages'],
        ], ['split' => false, 'single' => ['name' => 'routes']]);

        self::assertSame(['routes', 'pages'], array_map(static fn (File $file): string => $file->name, $files));
        self::assertSame(self::outputDirectory(), $files[0]->directory);
        self::assertSame(
            ['store.products.list', 'store.cart.show', 'store.cart.add', 'store.cart.remove', 'admin.users.list', 'admin.users.create', 'admin.users.update'],
            array_map(static fn (Route $route): string => $route->name, $files[0]->routes),
        );
        self::assertTrue($files[1]->standalone);
    }

    public function test_without_split_or_an_output_directory_nothing_is_combined(): void
    {
        self::assertSame([], $this->files([['name' => 'api', 'path' => 'routes']], ['split' => false]));
    }

    public function test_the_route_service_is_imported_from_the_output_directory_or_else_the_file_directory(): void
    {
        self::assertSame('routes', $this->files([['name' => 'api', 'path' => 'routes']])[0]->runtime);

        self::useTransformer(self::outputDirectory());

        self::assertSame(self::outputDirectory(), $this->files([['name' => 'api', 'path' => 'routes']])[0]->runtime);
    }

    public function test_path_parameters_are_typed_by_their_constraints(): void
    {
        $route = $this->route('users.posts');

        self::assertEquals([new Parameter('user', 'number', true), new Parameter('post', 'string | number', false)], $route->parameters);
        self::assertSame('https://app.test', $route->host);
        self::assertFalse($route->domain);
    }

    public function test_domain_parameters_are_parameters_too(): void
    {
        $route = $this->route('tenant.dashboard');

        self::assertEquals([new Parameter('account', 'string | number', true)], $route->parameters);
        self::assertSame('https://{account}.app.test', $route->host);
        self::assertTrue($route->domain);
    }

    public function test_only_the_typed_methods_are_kept(): void
    {
        self::assertSame(['get'], $this->route('store.cart.show')->methods);
        self::assertSame(['patch'], $this->route('store.cart.add')->methods);
    }
}
