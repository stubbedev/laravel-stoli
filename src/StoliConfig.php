<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;
use Throwable;

use function app_path;

final class StoliConfig
{
    public function __construct(private readonly array $config) {}

    public function splitModulesInFiles(): bool
    {
        return $this->config['split'] ?? true;
    }

    public function resourcesPath(): string
    {
        return $this->config['resources'];
    }

    public function modules(): array
    {
        return $this->config['modules'] ?? [];
    }

    public function defaultSingleFileModuleName(): string
    {
        return $this->config['single']['name'] ?? 'api';
    }

    public function axiosRouter(): bool
    {
        return $this->config['axios'] ?? false;
    }

    public function constants(): bool
    {
        return $this->config['constants']['enabled'] ?? true;
    }

    public function constantsFileName(): string
    {
        $name = $this->config['constants']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'constants';
    }

    /**
     * Where the constants file is written; the typescript-transformer output
     * directory unless the module config overrides it.
     */
    public function constantsPath(): ?string
    {
        $path = $this->config['constants']['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : $this->defaultOutputPath();
    }

    /**
     * The attributes a class must carry to have its constants exported.
     *
     * @return list<string>
     */
    public function constantsAttributes(): array
    {
        $attributes = $this->config['constants']['attributes'] ?? null;

        if (! is_array($attributes)) {
            return [TypeScriptConstants::class, TypeScript::class];
        }

        return array_values(array_filter($attributes, is_string(...)));
    }

    /**
     * The directories scanned for attributed classes. Falls back to the
     * directories the typescript-transformer itself discovers types in, so
     * constants are picked up wherever the enums already are.
     *
     * @return list<string>
     */
    public function constantsPaths(): array
    {
        $configured = $this->config['constants']['paths'] ?? null;

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(
                Utils::absolutePath(...),
                array_filter($configured, is_string(...))
            ));
        }

        $discovered = $this->transformerDirectories();

        if ($discovered !== []) {
            return $discovered;
        }

        return function_exists('app_path') ? [app_path()] : [];
    }

    /**
     * @return list<string>
     */
    private function transformerDirectories(): array
    {
        try {
            $spatieConfig = app('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig');
        } catch (Throwable) {
            return [];
        }

        $directories = $spatieConfig->directoriesToWatch ?? null;

        if (! is_array($directories)) {
            return [];
        }

        return array_values(array_unique(array_filter($directories, is_string(...))));
    }

    /**
     * Resolve the output directory from the spatie/typescript-transformer config.
     * Returns null when the transformer has not been registered in the container.
     */
    public function defaultOutputPath(): ?string
    {
        try {
            $spatieConfig = app('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig');
        } catch (Throwable) {
            return null;
        }

        $outputDirectory = isset($spatieConfig->outputDirectory) && is_string($spatieConfig->outputDirectory)
            ? rtrim($spatieConfig->outputDirectory, '/\\')
            : null;

        if (isset($spatieConfig->typesWriter) && $spatieConfig->typesWriter instanceof GlobalNamespaceWriter) {
            try {
                $prop = new \ReflectionProperty($spatieConfig->typesWriter, 'path');
                $prop->setAccessible(true);
                $writerPath = $prop->getValue($spatieConfig->typesWriter);

                if (is_string($writerPath) && $writerPath !== '') {
                    // The writer stores only the filename; strip it to get the directory.
                    $dir = dirname($writerPath);

                    if (! str_starts_with($writerPath, '/') && $outputDirectory !== null) {
                        return $outputDirectory . ($dir !== '.' ? DIRECTORY_SEPARATOR . $dir : '');
                    }

                    return $dir !== '.' ? $dir : $outputDirectory;
                }
            } catch (Throwable) {
            }
        }

        return $outputDirectory;
    }
}
