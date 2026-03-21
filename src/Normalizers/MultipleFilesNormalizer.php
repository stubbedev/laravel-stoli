<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Normalizers;

use StubbeDev\LaravelStoli\Support\ArrayList;

final readonly class MultipleFilesNormalizer implements Normalizer
{
    public function normalize(ArrayList $files): ArrayList
    {
        return $files;
    }
}
