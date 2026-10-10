<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use Illuminate\Support\Str;

final readonly class Module
{
    private const ANY = '*';

    /**
     * @param  string  $match  the path the module's routes are at or under: `/api` takes `api` and `api/users`, not `apidocs`; `*` takes every route
     * @param  string|null  $path  the directory the module's file is written to; null writes none
     * @param  list<string>|null  $names  `Str::is` patterns a route name must match; null lets every name through
     * @param  bool  $standalone  keeps its own file even when `split` is off, and gets no axios router: for routes that are not an API
     */
    public function __construct(
        public string $name,
        public string $match = self::ANY,
        public string $rootUrl = '',
        public string $prefix = '',
        public ?string $path = null,
        public bool $absolute = true,
        public ?string $stripPrefix = null,
        public ?array $names = null,
        public bool $standalone = false,
    ) {}

    /**
     * Whether a route belongs to the module: its URI is at or under `match`, and its
     * name passes the `names` filter.
     */
    public function matches(string $uri, string $name): bool
    {
        return $this->matchesUri($uri) && ($this->names === null || Str::is($this->names, $name));
    }

    /**
     * The name a route is published under, with `stripPrefix` taken off.
     */
    public function routeName(string $name): string
    {
        return $this->stripPrefix !== null && str_starts_with($name, $this->stripPrefix)
            ? substr($name, strlen($this->stripPrefix))
            : $name;
    }

    /**
     * The path a route is published at, behind the module's prefix.
     */
    public function uri(string $uri): string
    {
        return implode('/', array_filter(
            [trim($this->prefix, '/'), trim($uri, '/')],
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * The scheme and host a route's URL is built on, or null for a relative URL.
     *
     * A route domain is kept whether the module is absolute or not: the route is not
     * reachable anywhere else. It carries no scheme, so it borrows the root URL's;
     * without one it is protocol-relative, which a browser resolves against the page.
     */
    public function host(?string $domain = null): ?string
    {
        if ($domain === null) {
            $root = rtrim($this->rootUrl, '/');

            return $this->absolute && $root !== '' ? $root : null;
        }

        $domain = trim($domain, '/');

        if (str_contains($domain, '://')) {
            return $domain;
        }

        $scheme = parse_url($this->rootUrl, PHP_URL_SCHEME);

        return (is_string($scheme) ? "{$scheme}:" : '')."//{$domain}";
    }

    private function matchesUri(string $uri): bool
    {
        if ($this->match === self::ANY) {
            return true;
        }

        $prefix = trim($this->match, '/');
        $uri = trim($uri, '/');

        return $prefix === '' || $uri === $prefix || str_starts_with($uri, "{$prefix}/");
    }
}
