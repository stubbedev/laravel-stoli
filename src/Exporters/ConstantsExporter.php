<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use StubbeDev\LaravelStoli\Compilers\ConstantsFileCompiler;
use StubbeDev\LaravelStoli\ConstantGroupBuilder;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

/**
 * Writes the discovered PHP constants to a single TypeScript file in the
 * typescript-transformer output directory, next to stoli.js and the route files.
 */
final readonly class ConstantsExporter
{
    public function __construct(
        private StoliConfig $config,
        private ConstantGroupBuilder $builder,
        private ConstantsFileCompiler $compiler,
    ) {}

    public function generate(): Generation
    {
        $path = $this->config->constantsPath();

        if (! $this->config->constants() || $path === null) {
            return new Generation;
        }

        $file = join_paths($path, "{$this->config->constantsFileName()}.ts");

        try {
            $content = $this->compiler->compile($this->builder->groups());
        } catch (Throwable $error) {
            throw StoliException::cantExportConstants($error);
        }

        // With no constants left to export, a file an earlier run generated is stale.
        return $content === ''
            ? new Generation(removals: [new Removal($file)])
            : new Generation([new GeneratedFile($file, $content)]);
    }
}
