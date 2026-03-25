<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

final readonly class RouteServiceExporter
{
    public function __construct(
        private Filesystem $filesystem,
        private StoliConfig $config,
    ) {}

    public function publish(): void
    {
        try {
            $this->filesystem->makeDirectory($this->config->libraryPath(), 0755, true, true);

            $this->filesystem->put(
                join_paths($this->config->libraryPath(), 'stoli.js'),
                $this->filesystem->get(join_paths($this->config->resourcesPath(), 'stoli.stub'))
            );
            $this->filesystem->put(
                join_paths($this->config->libraryPath(), 'stoli.d.ts'),
                $this->filesystem->get(join_paths($this->config->resourcesPath(), 'stoli.d.stub'))
            );
        } catch (Throwable $error) {
            throw StoliException::cantOverrideLibrary($error);
        }
    }
}
