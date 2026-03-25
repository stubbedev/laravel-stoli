<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Spatie\TypeScriptTransformer\Formatters\Formatter;
use StubbeDev\LaravelStoli\Console\Command\StoliGenerateCommand;
use StubbeDev\LaravelStoli\Exporters\RoutesFileExporter;
use StubbeDev\LaravelStoli\Matchers\StartsWithRouteMatcher;
use StubbeDev\LaravelStoli\Normalizers\MultipleFilesNormalizer;
use StubbeDev\LaravelStoli\Normalizers\Normalizer;
use StubbeDev\LaravelStoli\Normalizers\SingleFileNormalizer;
use Throwable;

use function config;

final class StoliServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            StoliConfig::class,
            static fn () => new StoliConfig([
                ...config('stoli', []),
                'resources' => __DIR__ . '/../resources',
            ])
        );

        $this->app->singleton(
            Normalizer::class,
            function (Application $application) {
                $config = $application->make(StoliConfig::class);

                return $config->splitModulesInFiles()
                    ? new MultipleFilesNormalizer()
                    : new SingleFileNormalizer($config);
            }
        );

        $this->app->singleton(
            RouteMatcher::class,
            StartsWithRouteMatcher::class
        );

        $this->app->singleton(
            RoutesFileExporter::class,
            function (Application $application) {
                return new RoutesFileExporter(
                    $application->make(Normalizer::class),
                    $application->make(Filesystem::class),
                    $application->make(FileRouteBuilder::class),
                    $application->make(RouteHashCache::class),
                    $this->resolveFormatter($application),
                );
            }
        );

        $this->commands([
            StoliGenerateCommand::class,
        ]);
    }

    private function resolveFormatter(Application $application): ?Formatter
    {
        try {
            $config = $application->make('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig');

            return $config->formatter ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/stoli.php' => config_path('stoli.php'),
            ], 'stoli');
        }
    }
}
