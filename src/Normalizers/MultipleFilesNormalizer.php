<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Normalizers;

use Illuminate\Support\Collection;

final readonly class MultipleFilesNormalizer implements Normalizer
{
    public function normalize(Collection $files): Collection
    {
        return $files;
    }
}
