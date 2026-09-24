<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use StubbeDev\LaravelStoli\Compilers\ConstantsFileCompiler;
use StubbeDev\LaravelStoli\ConstantGroupBuilder;
use StubbeDev\LaravelStoli\GeneratedFileWriter;
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
        private GeneratedFileWriter $writer,
    ) {}

    public function publish(): void
    {
        if (! $this->config->constants()) {
            return;
        }

        $path = $this->config->constantsPath();

        if ($path === null) {
            return;
        }

        try {
            $content = $this->compiler->compile($this->builder->groups());

            // Nothing was discovered — leave whatever is on disk alone.
            if ($content === '') {
                return;
            }

            $this->writer->write(join_paths($path, "{$this->config->constantsFileName()}.ts"), $content);
        } catch (Throwable $error) {
            throw StoliException::cantExportConstants($error);
        }
    }
}
