<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

final readonly class Route
{
    /**
     * @param  string|null  $host  the route's own domain, e.g. {account}.example.com
     * @param  array<string, string>  $wheres  parameter name => regex constraint
     * @param  list<string>  $methods
     */
    public function __construct(
        private string $name,
        private string $rootUrl,
        private string $uri,
        private ?string $prefix,
        private bool $absolute,
        private ?string $host,
        private array $wheres = [],
        private array $methods = [],
        private ?string $stripPrefix = null,
        private ?DataType $dataRequestType = null,
        private ?DataType $dataResponseType = null,
    ) {}

    public function name(): string
    {
        if ($this->stripPrefix !== null && str_starts_with($this->name, $this->stripPrefix)) {
            return substr($this->name, strlen($this->stripPrefix));
        }

        return $this->name;
    }

    /**
     * The scheme and host URLs are built on, or null for relative URLs.
     *
     * A route domain carries no scheme, so it borrows the root URL's; without one it
     * is emitted protocol-relative, which a browser resolves against the page.
     */
    public function host(): ?string
    {
        if (! $this->absolute) {
            return null;
        }

        if ($this->host === null || ! $this->hasDomain()) {
            return trim($this->rootUrl, '/');
        }

        $host = trim($this->host, '/');

        if (str_contains($host, '://')) {
            return $host;
        }

        $scheme = parse_url($this->rootUrl, PHP_URL_SCHEME);

        return (is_string($scheme) ? "{$scheme}:" : '')."//{$host}";
    }

    /**
     * Whether the route has a domain of its own rather than the module's root URL.
     */
    public function hasDomain(): bool
    {
        return $this->host !== null && $this->host !== '';
    }

    public function uri(): string
    {
        $segments = array_filter([
            trim($this->prefix ?? '', '/'),
            trim($this->uri, '/'),
        ], static fn (string $segment): bool => $segment !== '');

        return implode('/', $segments);
    }

    /**
     * @return array<string, string>
     */
    public function wheres(): array
    {
        return $this->wheres;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function dataRequestType(): ?DataType
    {
        return $this->dataRequestType;
    }

    public function dataResponseType(): ?DataType
    {
        return $this->dataResponseType;
    }
}
