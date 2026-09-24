<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

/**
 * A TypeScript type the typescript-transformer generated for a Spatie Data class,
 * as a route's request or response type.
 *
 * Types in a `declare namespace` file are ambient globals referenced by their dotted
 * path and need no import; types in a module file are referenced by name and each
 * name in $imports has to be imported from $file.
 */
final readonly class DataType
{
    /**
     * @param  string  $type  the TypeScript type expression, e.g. App.Http.Data.UserData
     * @param  list<string>  $imports  names to import from $file; empty for ambient types
     */
    public function __construct(
        public string $type,
        public string $file,
        public array $imports = [],
    ) {}

    /**
     * The same type applied to a generic argument, e.g. ApiResponseData<UserData>.
     *
     * @param  list<string>  $imports  what the argument itself needs imported
     */
    public function withArgument(string $argument, array $imports = []): self
    {
        return new self(
            "{$this->type}<{$argument}>",
            $this->file,
            array_values(array_unique([...$this->imports, ...$imports])),
        );
    }
}
