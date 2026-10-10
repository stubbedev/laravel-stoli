<?php

declare(strict_types=1);

namespace App\Providers;

use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;
use Spatie\TypeScriptTransformer\Formatters\PrettierFormatter;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\FlatModuleWriter;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;

/**
 * Set up from the environment, so each e2e scenario picks its writer and formatter.
 */
final class TypeScriptTransformerServiceProvider extends TypeScriptTransformerApplicationServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(app_path())
            ->outputDirectory(resource_path('js/generated'))
            ->writer(match (env('E2E_WRITER', 'global')) {
                'module' => new ModuleWriter,
                'flat' => new FlatModuleWriter('types.ts'),
                default => new GlobalNamespaceWriter('generated.d.ts'),
            });

        if (env('E2E_FORMATTER') === 'prettier') {
            $config->formatter(PrettierFormatter::class);
        }
    }
}
