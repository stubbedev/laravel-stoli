<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use StubbeDev\LaravelStoli\Compilers\TypeScript;

/**
 * A TypeScript type expression, with the names it needs imported and the types it
 * needs declared.
 *
 * Types in a `declare namespace` file are ambient globals referenced by their dotted
 * path and need no import; types in a module file are referenced by name, and every
 * name in $imports has to be imported from the file it is keyed by. The types the
 * route service ships are keyed by self::RUNTIME, as only the compiler knows where it
 * is written. $declarations holds the types the expression refers to that the route
 * file itself declares, such as the input type of a request.
 *
 * Every type is built through the combinators below, so its imports and declarations
 * always travel with it.
 */
final readonly class DataType
{
    /**
     * The imports key of the route service module.
     */
    public const RUNTIME = '@stoli';

    /**
     * @param  string  $type  the TypeScript type expression, e.g. App.Http.Data.UserData
     * @param  array<string, list<string>>  $imports  file => the names to import from it
     * @param  array<string, string>  $declarations  name => the declaration of that name
     * @param  list<string>  $members  the members of a union, which needs parentheses to be an array item
     */
    public function __construct(
        public string $type,
        public array $imports = [],
        public array $declarations = [],
        private array $members = [],
    ) {}

    /**
     * A type exported by name from a module file.
     */
    public static function imported(string $name, string $file): self
    {
        return new self($name, [$file => [$name]]);
    }

    /**
     * A type the route service module exports, such as Paginated.
     */
    public static function runtime(string $name): self
    {
        return self::imported($name, self::RUNTIME);
    }

    /**
     * A string literal type.
     */
    public static function literal(string $value): self
    {
        return new self(TypeScript::string($value));
    }

    /**
     * Any of the types; one type is itself.
     */
    public static function union(self $first, self ...$rest): self
    {
        $types = [];

        foreach ([$first, ...$rest] as $type) {
            foreach ($type->members !== [] ? $type->members : [$type->type] as $member) {
                $types[$member] = $member;
            }
        }

        $members = array_values($types);

        return self::combine(implode(' | ', $members), [$first, ...array_values($rest)], count($members) > 1 ? $members : []);
    }

    /**
     * An object type with the given properties.
     *
     * @param  array<string, self>  $properties  name => type
     * @param  list<string>  $optional  the names of the properties that may be left out
     * @param  bool  $open  whether it may hold any other property too
     */
    public static function object(array $properties, array $optional = [], bool $open = false): self
    {
        $rendered = [];

        foreach ($properties as $name => $type) {
            $rendered[] = TypeScript::key((string) $name).(in_array($name, $optional, true) ? '?' : '').": {$type->type}";
        }

        if ($rendered === []) {
            return new self($open ? 'Record<string, unknown>' : 'Record<string, never>');
        }

        if ($open) {
            $rendered[] = '[key: string]: unknown';
        }

        return self::combine('{ '.implode('; ', $rendered).' }', array_values($properties));
    }

    /**
     * A type the route file declares as $declaration, referenced by $name. The types the
     * declaration refers to come along.
     */
    public static function declared(string $name, self $declaration): self
    {
        return new self($name, $declaration->imports, [...$declaration->declarations, $name => $declaration->type]);
    }

    /**
     * The same type applied to generic arguments, e.g. ApiResponseData<UserData>.
     */
    public function withArguments(self ...$arguments): self
    {
        if ($arguments === []) {
            return $this;
        }

        $types = array_map(static fn (self $argument): string => $argument->type, $arguments);

        return self::combine("{$this->type}<".implode(', ', $types).'>', [$this, ...array_values($arguments)]);
    }

    /**
     * Both types at once.
     */
    public function and(self $other): self
    {
        return self::combine("{$this->type} & {$other->type}", [$this, $other]);
    }

    /**
     * The type of this type's $key property.
     */
    public function at(string $key): self
    {
        return self::combine("{$this->type}[".TypeScript::string($key).']', [$this]);
    }

    /**
     * This type, or null.
     */
    public function nullable(): self
    {
        return self::union($this, new self('null'));
    }

    /**
     * This type as the value of an object with a single $key, the way laravel-data
     * wraps a response.
     */
    public function wrapped(string $key): self
    {
        return self::object([$key => $this]);
    }

    /**
     * An array of this type.
     */
    public function list(): self
    {
        return self::combine($this->members !== [] ? "({$this->type})[]" : "{$this->type}[]", [$this]);
    }

    /**
     * An object keyed by strings holding this type.
     */
    public function record(): self
    {
        return self::combine("Record<string, {$this->type}>", [$this]);
    }

    /**
     * Every import of the types, without duplicates.
     *
     * @return array<string, list<string>>
     */
    public static function merge(self ...$types): array
    {
        $imports = [];

        foreach ($types as $type) {
            foreach ($type->imports as $file => $names) {
                $imports[$file] = array_values(array_unique([...$imports[$file] ?? [], ...$names]));
            }
        }

        return $imports;
    }

    /**
     * Every declaration the types need.
     *
     * @return array<string, string>
     */
    public static function declarations(self ...$types): array
    {
        $declarations = [];

        foreach ($types as $type) {
            $declarations = [...$declarations, ...$type->declarations];
        }

        return $declarations;
    }

    /**
     * @param  list<self>  $parts
     * @param  list<string>  $members
     */
    private static function combine(string $type, array $parts, array $members = []): self
    {
        return new self($type, self::merge(...$parts), self::declarations(...$parts), $members);
    }
}
