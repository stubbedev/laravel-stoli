<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StubbeDev\LaravelStoli\Console\Command\StoliGenerateCommand;
use StubbeDev\LaravelStoli\Transformer\TransformerIntegration;

use function config;
use function app_path;
use function base_path;
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

        // Shared, so the transformer resolves its types once per run.
        $this->app->singleton(
            TransformedTypes::class,
            static fn (Application $app): TransformedTypes => new TransformedTypes($app->make(TransformerOutput::class)->config)
        );

        // Shared, so a batch started by the Publisher covers every write.
        $this->app->singleton(GeneratedFileWriter::class);

        // typescript:transform generates Stoli's files too, unless that is turned off.
        $this->app->extend(
            TypeScriptTransformerConfig::class,
            static fn (TypeScriptTransformerConfig $config, Application $app): TypeScriptTransformerConfig => config('stoli.transform', true) === false
                ? $config
                : TransformerIntegration::add($config, $app, [base_path('routes'), app_path()])
        );

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
