<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
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
    ) {}

    public function publish(): void
    {
        if (! $this->config->axiosRouter()) {
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

        $modules = array_filter(
            $this->config->modules(),
            static fn (array $module): bool => ! ($module['standalone'] ?? false),
        );
        $multiple = count($modules) > 1;

        foreach ($modules as $module) {
            $name = $module['name'];
            $path = $module['path'] ?? $this->config->defaultOutputPath();
            $filename = $multiple ? "{$name}.router.ts" : 'router.ts';
            $this->generate($name, $path, $filename);
        }
    }

    private function generate(string $name, ?string $path, string $filename): void
    {
        if ($path === null) {
            return;
        }

        $stub = $this->filesystem->get(
            join_paths($this->config->resourcesPath(), 'stoli.router.stub')
        );

        $content = str_replace(
            ['{{MODULE}}', '{{STUDLY}}', '{{STOLI}}'],
            [$name, Str::studly($name), $this->stoliImportPath($path)],
            $stub
        );

        try {
            $this->filesystem->makeDirectory($path, 0755, true, true);
            $this->filesystem->put(join_paths($path, $filename), $content);
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
