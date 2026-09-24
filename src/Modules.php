<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use StubbeDev\LaravelStoli\Items\Module;
use StubbeDev\LaravelStoli\Support\SecureList;

final class Modules extends SecureList
{
    public static function type(): string
    {
        return Module::class;
    }
}
