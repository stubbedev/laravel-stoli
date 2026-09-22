<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use Spatie\TypeScriptTransformer\Formatters\Formatter;
use StubbeDev\LaravelStoli\Compilers\ConstantsFileCompiler;
use StubbeDev\LaravelStoli\ConstantGroupBuilder;
use StubbeDev\LaravelStoli\RouteHashCache;
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
    private ConstantsFileCompiler $compiler;

    public function __construct(
        private Filesystem $filesystem,
        private StoliConfig $config,
        private ConstantGroupBuilder $builder,
        private RouteHashCache $hashCache,
        private ?Formatter $formatter = null,
    ) {
        $this->compiler = new ConstantsFileCompiler();
    }

    public function publish(): void
    {
        if (! $this->config->constants()) {
            return;
        }

        $path = $this->config->constantsPath();

        if ($path === null) {
            return;
        }

        $name = $this->config->constantsFileName();

        try {
            $content = $this->compiler->compile($this->builder->groups());

            // Nothing was discovered — leave whatever is on disk alone.
            if ($content === '') {
                return;
            }

            $filePath = join_paths($path, "{$name}.{$this->compiler->extension()}");

            // Skip writing when the compiled content has not changed since the last run.
            if ($this->hashCache->isUnchanged("{$path}/{$name}", $content, $filePath)) {
                return;
            }

            $this->filesystem->makeDirectory($path, 0755, true, true);

            $this->filesystem->put($filePath, $content);

            $absolutePath = str_starts_with($filePath, '/') ? $filePath : base_path($filePath);
            $this->formatter?->format([$absolutePath]);

            $this->hashCache->record("{$path}/{$name}", $content, $filePath);
        } catch (Throwable $error) {
            throw StoliException::cantExportConstants($error);
        }
    }
}
