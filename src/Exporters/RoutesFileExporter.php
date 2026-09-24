<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\GeneratedFileWriter;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Normalizers\Normalizer;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

final readonly class RoutesFileExporter
{
    public function __construct(
        private Normalizer $filesNormalizer,
        private FileRouteBuilder $fileRouteBuilder,
        private TypeScriptFileCompiler $compiler,
        private GeneratedFileWriter $writer,
    ) {}

    public function publish(): void
    {
        foreach ($this->filesNormalizer->normalize($this->fileRouteBuilder->files()) as $file) {
            $this->export($file);
        }
    }

    private function export(File $file): void
    {
        $path = $file->path();

        if ($path === null) {
            return;
        }

        try {
            $this->writer->write(join_paths($path, "{$file->name()}.ts"), $this->compiler->compile($file));
        } catch (Throwable $error) {
            throw StoliException::cantExportModule($file->name(), $error);
        }
    }
}
