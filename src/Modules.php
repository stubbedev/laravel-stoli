<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use StubbeDev\LaravelStoli\Items\Module;
use StubbeDev\LaravelStoli\Support\ArrayList;
use StubbeDev\LaravelStoli\Support\SecureList;

final class Modules extends SecureList
{
    static public function type(): string
    {
        return Module::class;
    }

    public function matches(): ArrayList
    {
        return $this->map(fn(Module $module) => $module->match());
    }
}
