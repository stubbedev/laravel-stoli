<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Spatie\TypeScriptTransformer\Writers\Writer;
use StubbeDev\LaravelStoli\GeneratedFileWriter;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliServiceProvider;
use StubbeDev\LaravelStoli\TransformedTypes;
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
            LaravelDataServiceProvider::class,
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
     * The directory holding the classes the transformer transforms in the tests.
     */
    protected const TRANSFORMED = __DIR__.'/Fixtures/TypeScript';

    /**
     * Bind a typescript-transformer config writing to $directory, set up the way an
     * application would: laravel-data classes and enums from the fixtures.
     *
     * @param  list<string>  $directories  the directories transformed
     */
    protected static function useTransformer(string $directory, ?Writer $writer = null, array $directories = [self::TRANSFORMED]): TypeScriptTransformerConfig
    {
        (new Filesystem)->ensureDirectoryExists($directory);

        $config = (new TypeScriptTransformerConfigFactory)
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(...$directories)
            ->outputDirectory($directory)
            ->writer($writer ?? new GlobalNamespaceWriter('index.d.ts'))
            ->withoutManifest()
            ->get();

        // Bound the way an application binds it, so Stoli's container extension applies.
        app()->forgetInstance(TypeScriptTransformerConfig::class);
        app()->singleton(TypeScriptTransformerConfig::class, static fn (): TypeScriptTransformerConfig => $config);
        self::forgetResolved();

        return app()->make(TypeScriptTransformerConfig::class);
    }

    /**
     * Drop the services built from the config, so the next ones read it anew.
     */
    protected static function forgetResolved(): void
    {
        foreach ([TransformerOutput::class, TransformedTypes::class, StoliConfig::class, GeneratedFileWriter::class] as $service) {
            app()->forgetInstance($service);
        }
    }

    /**
     * Write $generation the way the Publisher does.
     */
    protected static function apply(Generation $generation): void
    {
        $writer = app()->make(GeneratedFileWriter::class);

        foreach ($generation->files as $file) {
            $writer->write($file);
        }

        foreach ($generation->removals as $removal) {
            $writer->remove($removal);
        }
    }

    /**
     * Run the transformer bound by useTransformer(), the way `typescript:transform` does.
     */
    protected static function transform(): void
    {
        TypeScriptTransformer::create(app()->make(TypeScriptTransformerConfig::class))->execute();
    }
}
