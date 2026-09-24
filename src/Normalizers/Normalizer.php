<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Normalizers;

use Illuminate\Support\Collection;
use StubbeDev\LaravelStoli\Items\File;

interface Normalizer
{
    /**
     * @param  Collection<int, File>  $files
     * @return Collection<int, File>
     */
    public function normalize(Collection $files): Collection;
}
