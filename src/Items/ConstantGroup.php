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
        public string $className,
        public array $namespace,
        public string $name,
        public array $constants,
    ) {}

    /**
     * The dotted path the constants are reachable at in the generated file,
     * e.g. App.Support.Permission.
     */
    public function path(): string
    {
        return implode('.', [...$this->namespace, $this->name]);
    }

    /**
     * @param  array<string, mixed>  $constants
     */
    public function with(array $constants): self
    {
        return new self($this->className, $this->namespace, $this->name, $constants);
    }
}
