<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use StubbeDev\LaravelStoli\Support\ArrayList;

final readonly class File
{
    public function __construct(
        private string $name,
        private ?string $path,
        private ArrayList $routes,
        private bool $standalone = false,
    ) {}

    public static function from(Module $module, ArrayList $routes): self
    {
        return new self($module->name(), $module->path(), $routes, $module->standalone());
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function routes(): ArrayList
    {
        return $this->routes;
    }

    public function standalone(): bool
    {
        return $this->standalone;
    }
}
