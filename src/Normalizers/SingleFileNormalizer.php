<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Normalizers;

use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\Support\ArrayList;

final readonly class SingleFileNormalizer implements Normalizer
{
    public function __construct(
        private StoliConfig $config,
    )
    {
    }

    public function normalize(ArrayList $files): ArrayList
    {
        return new ArrayList([
            new File(
                $this->config->defaultSingleFileModuleName(),
                $this->config->defaultSingleFileOutputPath(),
                $files->flatMap(static fn(File $file) => $file->routes())
            )
        ]);
    }
}
