<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use function ltrim;

final readonly class Module
{
    private string $match;

    public function __construct(
        string          $match,
        private string  $rootUrl,
        private string  $name,
        private string  $prefix,
        private string  $path,
        private bool    $absolute,
        private ?string $stripPrefix = null,
    )
    {
        $this->match = ltrim($match, '/');
    }

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

    public function path(): string
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
}
