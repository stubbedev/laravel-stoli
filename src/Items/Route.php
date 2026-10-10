<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

/**
 * A route as it is published: everything here is final, nothing is derived later.
 */
final readonly class Route
{
    /**
     * The HTTP methods a route is typed by. Laravel pairs every GET route with HEAD, so
     * HEAD needs no type of its own.
     */
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @param  string|null  $host  the scheme and host the URL is built on; null for a relative URL
     * @param  bool  $domain  whether $host is the route's own domain, which a rootUrl does not replace
     * @param  list<Parameter>  $parameters  the placeholders in $host and $uri
     * @param  list<value-of<self::METHODS>>  $methods
     * @param  DataType|null  $request  the Data object the action takes
     * @param  DataType|null  $response  what the action responds with
     * @param  string  $status  the TypeScript type of the status it responds with when it succeeds
     */
    public function __construct(
        public string $name,
        public string $uri,
        public ?string $host = null,
        public bool $domain = false,
        public array $parameters = [],
        public array $methods = [],
        public ?DataType $request = null,
        public ?DataType $response = null,
        public string $status = 'number',
    ) {}
}
