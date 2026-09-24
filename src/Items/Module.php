<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use Illuminate\Support\Str;

final readonly class Module
{
    /**
     * @param  list<string>|null  $names  `Str::is` patterns a route name must match; null lets every name through
     */
    public function __construct(
        private string $match,
        private string $rootUrl,
        private string $name,
        private string $prefix,
        private ?string $path,
        private bool $absolute,
        private ?string $stripPrefix = null,
        private ?array $names = null,
        private bool $standalone = false,
    ) {}

    public function match(): string
    {
        return $this->match;
    }

    public function rootUrl(): string
    {
        return $this->rootUrl;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function absolute(): bool
    {
        return $this->absolute;
    }

    public function stripPrefix(): ?string
    {
        return $this->stripPrefix;
    }

    /**
     * A standalone module keeps its own file even when `split` is off, and gets no
     * axios router: for routes that are not an API, such as a single-page app's pages.
     */
    public function standalone(): bool
    {
        return $this->standalone;
    }

    /**
     * Whether a route name passes the module's `names` filter. No filter lets every
     * name through; otherwise the name must match one of the `Str::is` patterns.
     */
    public function matchesName(string $name): bool
    {
        return $this->names === null || Str::is($this->names, $name);
    }
}
