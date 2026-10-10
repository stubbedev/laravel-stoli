<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use StubbeDev\LaravelStoli\Exporters\ConstantsExporter;
use StubbeDev\LaravelStoli\Exporters\RouterExporter;
use StubbeDev\LaravelStoli\Exporters\RoutesFileExporter;
use StubbeDev\LaravelStoli\Exporters\RuntimeExporter;
use StubbeDev\LaravelStoli\Exporters\UrlsExporter;
use StubbeDev\LaravelStoli\Generation\Generation;

/**
 * Everything a run generates, and what it leaves behind to remove. stoli:generate
 * writes it, `--check` compares it with the files on disk, and inside
 * typescript:transform the transformer writes it.
 */
final readonly class Generator
{
    public function __construct(
        private FileRouteBuilder $files,
        private RuntimeExporter $runtime,
        private RoutesFileExporter $routes,
        private RouterExporter $routers,
        private ConstantsExporter $constants,
        private UrlsExporter $urls,
    ) {}

    public function generate(): Generation
    {
        $files = $this->files->files();

        return $this->runtime->generate($files)->with(
            $this->routes->generate($files),
            $this->routers->generate($files),
            $this->urls->generate($files),
            $this->constants->generate(),
        );
    }
}
