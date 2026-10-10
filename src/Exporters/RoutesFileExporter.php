<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

final readonly class RoutesFileExporter
{
    public function __construct(private TypeScriptFileCompiler $compiler) {}

    /**
     * @param  list<File>  $files
     */
    public function generate(array $files): Generation
    {
        $generated = [];

        foreach ($files as $file) {
            try {
                $generated[] = new GeneratedFile($file->path(), $this->compiler->compile($file));
            } catch (Throwable $error) {
                throw StoliException::cantExportModule($file->name, $error);
            }
        }

        return new Generation($generated);
    }
}
