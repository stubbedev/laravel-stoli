<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use Throwable;
use StubbeDev\LaravelStoli\Compilers\JsonFileCompiler;
use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Normalizers\Normalizer;

use function Illuminate\Filesystem\join_paths;

final readonly class RoutesFileExporter
{
    private TypeScriptFileCompiler $compiler;

    public function __construct(
        private Normalizer       $filesNormalizer,
        private Filesystem       $filesystem,
        private FileRouteBuilder $fileRouteBuilder,
    )
    {
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
            $this->filesystem->makeDirectory($file->path(), 0755, true, true);
            $this->filesystem->put(
                join_paths($file->path(), "{$file->name()}.{$this->compiler->extension()}"),
                $this->compiler->compile($file)
            );
        } catch (Throwable $error) {
            throw StoliException::cantExportModule($file->name(), $error);
        }
    }
}
