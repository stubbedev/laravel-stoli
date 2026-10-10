<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

/**
 * A `{placeholder}` in a route's path or domain.
 */
final readonly class Parameter
{
    /**
     * @param  string  $type  the TypeScript type its `where` constraint allows
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required,
    ) {}
}
