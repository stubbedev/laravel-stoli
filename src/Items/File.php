<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use function Illuminate\Filesystem\join_paths;

/**
 * One generated route file.
 */
final readonly class File
{
    /**
     * @param  string  $directory  where the file is written
     * @param  string  $runtime  the directory holding the route service the file imports
     * @param  list<Route>  $routes  unique by name
     * @param  bool  $standalone  gets no axios router
     */
    public function __construct(
        public string $name,
        public string $directory,
        public string $runtime,
        public array $routes,
        public bool $standalone = false,
    ) {}

    public function path(): string
    {
        return join_paths($this->directory, "{$this->name}.ts");
    }
}
