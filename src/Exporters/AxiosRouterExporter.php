<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;

use function Illuminate\Filesystem\join_paths;

final readonly class AxiosRouterExporter
{
    public function __construct(
        private Filesystem  $filesystem,
        private StoliConfig $config,
    )
    {
    }

    public function publish(): void
    {
        if (!$this->config->axiosRouter()) {
            return;
        }

        if (!$this->config->splitModulesInFiles()) {
            $this->generate(
                $this->config->defaultSingleFileModuleName(),
                $this->config->defaultSingleFileOutputPath(),
                'router.ts',
            );
            return;
        }

        $modules  = $this->config->modules();
        $multiple = count($modules) > 1;

        foreach ($modules as $module) {
            $name     = $module['name'];
            $path     = $module['path'] ?? $this->config->libraryPath();
            $filename = $multiple ? "{$name}.router.ts" : 'router.ts';
            $this->generate($name, $path, $filename);
        }
    }

    private function generate(string $name, string $path, string $filename): void
    {
        $stub = $this->filesystem->get(
            join_paths($this->config->resourcesPath(), 'stoli.router.stub')
        );

        $content = str_replace(
            ['{{MODULE}}', '{{STUDLY}}'],
            [$name, Str::studly($name)],
            $stub
        );

        try {
            $this->filesystem->makeDirectory($path, 0755, true, true);
            $this->filesystem->put(join_paths($path, $filename), $content);
        } catch (Throwable $error) {
            throw StoliException::cantExportModule($name, $error);
        }
    }
}
