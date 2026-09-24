<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as BaseTestCase;
use StubbeDev\LaravelStoli\StoliServiceProvider;
use StubbeDev\LaravelStoli\TransformerOutput;

use function app;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    abstract protected static function modules(): array;

    protected function getEnvironmentSetUp($app): void
    {
        $app->make('config')
            ->set('stoli', [
                'split' => false,
                'modules' => static::modules(),
                ...static::config(),
            ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            StoliServiceProvider::class,
        ];
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->group(['prefix' => 'api'], function (Router $router) {
            $router->group(['prefix' => 'store/products'], function (Router $router) {
                $router->get('/', static fn () => [])->name('store.products.list');
            });

            $router->group(['prefix' => 'store/cart'], function (Router $router) {
                $router->get('/', static fn () => [])->name('store.cart.show');
                $router->patch('{product_id}', static fn () => [])->name('store.cart.add');
                $router->delete('{product_id}', static fn () => [])->name('store.cart.remove');
            });

            $router->group(['prefix' => 'admin/products'], function (Router $router) {
                $router->post('{id}', static fn () => [])->name('admin.products.create');
                $router->patch('{id}', static fn () => [])->name('admin.products.update');
                $router->get('{id}', static fn () => [])->name('admin.products.show');
            });

            $router->group(['prefix' => 'admin/users'], function (Router $router) {
                $router->get('/', static fn () => [])->name('admin.users.list');
                $router->post('{id}', static fn () => [])->name('admin.users.create');
                $router->patch('{id}', static fn () => [])->name('admin.users.update');
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected static function config(): array
    {
        return [];
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $service
     * @return T
     *
     * @throws BindingResolutionException
     */
    protected static function create(string $service): mixed
    {
        return app()->make($service);
    }

    /**
     * Bind a stand-in for the typescript-transformer config. TransformerOutput reads
     * it by its public properties, so the output directory is all it has to carry.
     */
    protected static function useTransformerOutputDirectory(string $directory): void
    {
        app()->instance(TransformerOutput::BINDING, new class($directory)
        {
            public function __construct(public string $outputDirectory) {}
        });
    }
}
