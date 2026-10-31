<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

enum Query: string
{
    use RuntimeModule;

    case TanStack = 'tanstack';
}
