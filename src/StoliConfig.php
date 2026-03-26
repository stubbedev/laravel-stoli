<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Throwable;

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
