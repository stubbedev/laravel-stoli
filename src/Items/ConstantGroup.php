<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

/**
 * One PHP class' constants, ready to be emitted as a leaf of the generated
 * constants object: the namespace segments it nests under, the key it is
 * published as, and the constant name => value pairs themselves.
 */
final readonly class ConstantGroup
{
    /**
     * @param  list<string>  $namespace  namespace segments, outermost first
     * @param  array<string, mixed>  $constants
     */
    public function __construct(
        private string $className,
        private array $namespace,
        private string $name,
        private array $constants,
    ) {}

    public function className(): string
    {
        return $this->className;
    }

    /**
     * @return list<string>
     */
    public function namespace(): array
    {
        return $this->namespace;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function constants(): array
    {
        return $this->constants;
    }

    /**
     * The dotted path the constants are reachable at in the generated file,
     * e.g. App.Support.Permission.
     */
    public function path(): string
    {
        return implode('.', [...$this->namespace, $this->name]);
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return [...$this->namespace, $this->name];
    }
}
