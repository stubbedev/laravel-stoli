<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use StubbeDev\LaravelStoli\Console\Command\StoliGenerateCommand;
use StubbeDev\LaravelStoli\Matchers\StartsWithRouteMatcher;
use StubbeDev\LaravelStoli\Normalizers\MultipleFilesNormalizer;
use StubbeDev\LaravelStoli\Normalizers\Normalizer;
use StubbeDev\LaravelStoli\Normalizers\SingleFileNormalizer;

use function config;
use function config_path;

final class StoliServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TransformerOutput::class,
            static fn (Application $app): TransformerOutput => TransformerOutput::fromContainer($app)
        );

        $this->app->singleton(
            StoliConfig::class,
            static function (Application $app): StoliConfig {
                $config = config('stoli', []);

                return new StoliConfig(is_array($config) ? $config : [], $app->make(TransformerOutput::class));
            }
        );

        $this->app->singleton(
            Normalizer::class,
            static function (Application $app): Normalizer {
                $config = $app->make(StoliConfig::class);

                return $config->splitModulesInFiles()
                    ? new MultipleFilesNormalizer
                    : new SingleFileNormalizer($config);
            }
        );

        $this->app->singleton(RouteMatcher::class, StartsWithRouteMatcher::class);

        // Shared, so a batch started by the Publisher covers every exporter's writes.
        $this->app->singleton(GeneratedFileWriter::class);

        $this->commands([
            StoliGenerateCommand::class,
        ]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/stoli.php' => config_path('stoli.php'),
            ], 'stoli');
        }
    }
}
