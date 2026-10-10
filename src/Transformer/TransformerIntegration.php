<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Transformer;

use Illuminate\Contracts\Container\Container;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;

/**
 * Adds Stoli to a typescript-transformer config, so `typescript:transform` generates
 * Stoli's files too, watch mode included.
 */
final class TransformerIntegration
{
    /**
     * @param  list<string>  $directories  the directories whose PHP files the routes are read from
     */
    public static function add(TypeScriptTransformerConfig $config, Container $container, array $directories): TypeScriptTransformerConfig
    {
        foreach ($config->transformedProviders as $provider) {
            if ($provider instanceof StoliTransformedProvider) {
                return $config;
            }
        }

        $provider = new StoliTransformedProvider($container, $directories);

        // The config is read-only, so it is rebuilt with Stoli's provider added, and the
        // directories it watches, which the config gathered from its providers when built.
        return new TypeScriptTransformerConfig(
            outputDirectory: $config->outputDirectory,
            transformedProviders: [...$config->transformedProviders, $provider],
            typesWriter: $config->typesWriter,
            formatter: $config->formatter,
            directoriesToWatch: array_values(array_unique([...$config->directoriesToWatch, ...$provider->directoriesToWatch()])),
            providedVisitorClosures: $config->providedVisitorClosures,
            connectedVisitorClosures: $config->connectedVisitorClosures,
            transformers: $config->transformers,
            configPaths: $config->configPaths,
            generateManifest: $config->generateManifest,
        );
    }
}
