<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Matchers;

use StubbeDev\LaravelStoli\RouteMatcher;

/**
 * Matches the routes at or under a path: `/api` takes `api` and `api/users`, but
 * not `apidocs`. `*` and `/` take every route.
 */
final readonly class StartsWithRouteMatcher implements RouteMatcher
{
    private const ANY = '*';

    public function matches(string $fullUri, string $pattern): bool
    {
        if ($pattern === self::ANY) {
            return true;
        }

        $prefix = trim($pattern, '/');
        $uri = trim($fullUri, '/');

        return $prefix === '' || $uri === $prefix || str_starts_with($uri, "{$prefix}/");
    }
}
