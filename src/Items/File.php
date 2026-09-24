<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use Illuminate\Support\Collection;

final readonly class File
{
    /**
     * @param  Collection<int, Route>  $routes
     */
    public function __construct(
        private string $name,
        private ?string $path,
        private Collection $routes,
        private bool $standalone = false,
    ) {}

    /**
     * @param  Collection<int, Route>  $routes
     */
    public static function from(Module $module, Collection $routes): self
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

    /**
     * @return Collection<int, Route>
     */
    public function routes(): Collection
    {
        return $this->routes;
    }

    public function standalone(): bool
    {
        return $this->standalone;
    }
}
