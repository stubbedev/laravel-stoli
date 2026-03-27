<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Matchers;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\RouteMatcher;

final readonly class StartsWithRouteMatcher implements RouteMatcher
{
    private const ANY = '*';

    public function matches(string $fullUri, string $pattern): bool
    {
        return $pattern === self::ANY || Str::startsWith($fullUri, ltrim($pattern, '/'));
    }
}
