<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Normalizers;

use Illuminate\Support\Collection;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\StoliConfig;

/**
 * Combines every module into the single configured file. Standalone modules keep
 * their own file; with nothing left to combine, no combined file is written.
 */
final readonly class SingleFileNormalizer implements Normalizer
{
    public function __construct(
        private StoliConfig $config,
    ) {}

    public function normalize(Collection $files): Collection
    {
        $standalone = $files->filter(static fn (File $file): bool => $file->standalone())->values();
        $combined = $files->reject(static fn (File $file): bool => $file->standalone());

        if ($combined->isEmpty()) {
            return $standalone;
        }

        $routes = $combined
            ->flatMap(static fn (File $file): Collection => $file->routes())
            ->unique(static fn (Route $route): string => $route->name())
            ->values();

        return (new Collection([
            new File($this->config->defaultSingleFileModuleName(), $this->config->defaultOutputPath(), $routes),
        ]))->merge($standalone);
    }
}
