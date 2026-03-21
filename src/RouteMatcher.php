<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

interface RouteMatcher
{
    public function matches(string $fullUri, string $pattern): bool;
}
