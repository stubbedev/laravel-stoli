<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

use StubbeDev\LaravelStoli\Items\File;

interface Compiler
{
    public function compile(File $file): string;

    public function extension(): string;
}
