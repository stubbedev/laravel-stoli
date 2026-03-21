<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Matchers;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\RouteMatcher;

final readonly class StartsWithRouteMatcher implements RouteMatcher
{
    const ANY = '*';

    public function matches(string $fullUri, string $pattern): bool
    {
        return self::ANY === $pattern || Str::startsWith($fullUri, $pattern);
    }
}
