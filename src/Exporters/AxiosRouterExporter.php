<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\GeneratedFileWriter;
use StubbeDev\LaravelStoli\Items\Module;
use StubbeDev\LaravelStoli\ModulesProvider;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Utils;
use Throwable;

use function Illuminate\Filesystem\join_paths;

final readonly class AxiosRouterExporter
{
    public function __construct(
        private Filesystem $filesystem,
        private StoliConfig $config,
        private ModulesProvider $modules,
        private GeneratedFileWriter $writer,
    ) {}

    public function publish(): void
    {
        if (! $this->config->axiosRouter()) {
            return;
        }

        // Standalone modules are not an API and get no router.
        $routed = $this->modules->modules()->reject(static fn (Module $module): bool => $module->standalone());

        if ($routed->isEmpty()) {
            return;
        }

        if (! $this->config->splitModulesInFiles()) {
            $this->generate(
                $this->config->defaultSingleFileModuleName(),
                $this->config->defaultOutputPath(),
                'router.ts',
            );

            return;
        }

        $multiple = $routed->count() > 1;

        foreach ($routed as $module) {
            $this->generate(
                $module->name(),
                $module->path(),
                $multiple ? "{$module->name()}.router.ts" : 'router.ts',
            );
        }
    }

    private function generate(string $name, ?string $path, string $filename): void
    {
        if ($path === null) {
            return;
        }

        try {
            $content = str_replace(
                ['{{MODULE}}', '{{STUDLY}}', '{{STOLI}}'],
                [$name, Str::studly($name), $this->stoliImportPath($path)],
                $this->filesystem->get(join_paths($this->config->resourcesPath(), 'stoli.router.stub'))
            );

            $this->writer->write(join_paths($path, $filename), $content, format: false);
        } catch (Throwable $error) {
            throw StoliException::cantExportModule($name, $error);
        }
    }

    /**
     * The route service is written to the typescript-transformer output directory, which is
     * not necessarily where this module lives — import it by its path relative to the router.
     */
    private function stoliImportPath(string $modulePath): string
    {
        $outputPath = $this->config->defaultOutputPath();

        if ($outputPath === null) {
            return './stoli';
        }

        return Utils::relativeImportPath(
            Utils::absolutePath($modulePath),
            join_paths(Utils::absolutePath($outputPath), 'stoli'),
        );
    }
}
