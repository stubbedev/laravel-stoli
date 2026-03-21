<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Normalizers;

use StubbeDev\LaravelStoli\Support\ArrayList;

interface Normalizer
{
    public function normalize(ArrayList $files): ArrayList;
}
