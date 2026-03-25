<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use Spatie\TypeScriptTransformer\Formatters\Formatter;
use StubbeDev\LaravelStoli\Compilers\JsonFileCompiler;
use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Normalizers\Normalizer;
use StubbeDev\LaravelStoli\RouteHashCache;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

final readonly class RoutesFileExporter
{
    private TypeScriptFileCompiler $compiler;

    public function __construct(
        private Normalizer $filesNormalizer,
        private Filesystem $filesystem,
        private FileRouteBuilder $fileRouteBuilder,
        private RouteHashCache $hashCache,
        private ?Formatter $formatter = null,
    ) {
        $this->compiler = new TypeScriptFileCompiler(new JsonFileCompiler());
    }

    public function publish(): void
    {
        $this->filesNormalizer
            ->normalize($this->fileRouteBuilder->files())
            ->each($this->export(...));
    }

    private function export(File $file): void
    {
        try {
            $content = $this->compiler->compile($file);

            // Skip writing when the compiled content has not changed since the last run.
            if ($this->hashCache->isUnchanged($file, $content)) {
                return;
            }

            $this->filesystem->makeDirectory($file->path(), 0755, true, true);

            $filePath = join_paths($file->path(), "{$file->name()}.{$this->compiler->extension()}");

            $this->filesystem->put($filePath, $content);

            $absolutePath = str_starts_with($filePath, '/') ? $filePath : base_path($filePath);
            $this->formatter?->format([$absolutePath]);

            $this->hashCache->record($file, $content);
        } catch (Throwable $error) {
            throw StoliException::cantExportModule($file->name(), $error);
        }
    }
}
