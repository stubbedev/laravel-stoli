<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use StubbeDev\LaravelStoli\Compilers\TypeScript;

/**
 * A TypeScript type a route takes or returns, built from the types the
 * typescript-transformer generated for Spatie Data classes.
 *
 * Types in a `declare namespace` file are ambient globals referenced by their dotted
 * path and need no import; types in a module file are referenced by name, and every
 * name in $imports has to be imported from the file it is keyed by.
 */
final readonly class DataType
{
    /**
     * @param  string  $type  the TypeScript type expression, e.g. App.Http.Data.UserData
     * @param  array<string, list<string>>  $imports  file => the names to import from it
     */
    public function __construct(
        public string $type,
        public array $imports = [],
    ) {}

    /**
     * A type exported by name from a module file.
     */
    public static function imported(string $name, string $file): self
    {
        return new self($name, [$file => [$name]]);
    }

    /**
     * The same type applied to generic arguments, e.g. ApiResponseData<UserData>. A
     * string argument is an already rendered type that needs no import.
     */
    public function withArgument(self|string ...$arguments): self
    {
        $types = [];
        $imports = $this->imports;

        foreach ($arguments as $argument) {
            if (is_string($argument)) {
                $types[] = $argument;
            } else {
                $types[] = $argument->type;
                $imports = self::merge($imports, $argument->imports);
            }
        }

        return new self("{$this->type}<".implode(', ', $types).'>', $imports);
    }

    /**
     * This type as the value of an object with a single $key, the way laravel-data
     * wraps a response.
     */
    public function wrapped(string $key): self
    {
        return new self('{ '.TypeScript::key($key).": {$this->type} }", $this->imports);
    }

    /**
     * An array of this type.
     */
    public function list(): self
    {
        return new self("{$this->type}[]", $this->imports);
    }

    /**
     * @param  array<string, list<string>>  $imports
     * @param  array<string, list<string>>  $more
     * @return array<string, list<string>>
     */
    private static function merge(array $imports, array $more): array
    {
        foreach ($more as $file => $names) {
            $imports[$file] = array_values(array_unique([...$imports[$file] ?? [], ...$names]));
        }

        return $imports;
    }
}
