<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
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
        private Filesystem $filesystem,
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

        $file = join_paths($path, "{$this->config->constantsFileName()}.ts");

        try {
            $content = $this->compiler->compile($this->builder->groups());

            if ($content === '') {
                $this->removeGenerated($file);

                return;
            }

            $this->writer->write($file, $content);
        } catch (Throwable $error) {
            throw StoliException::cantExportConstants($error);
        }
    }

    /**
     * With no constants left to export, a file an earlier run generated is stale. One
     * without the generated header was not written by Stoli and is left alone.
     */
    private function removeGenerated(string $file): void
    {
        if ($this->filesystem->exists($file)
            && str_starts_with($this->filesystem->get($file), ConstantsFileCompiler::HEADER)) {
            $this->filesystem->delete($file);
        }
    }
}
