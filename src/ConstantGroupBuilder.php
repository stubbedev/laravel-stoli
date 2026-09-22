<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use ReflectionClass;
use Spatie\StructureDiscoverer\Discover;
use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;
use StubbeDev\LaravelStoli\Items\ConstantGroup;
use Throwable;

use function class_basename;

/**
 * Discovers the classes whose constants should be exported — the same way the
 * typescript-transformer discovers the classes and enums it transforms: by
 * scanning the configured directories for an attribute.
 *
 * Only public constants declared on the class itself are collected; inherited
 * and interface constants are left to the class that declares them.
 */
final readonly class ConstantGroupBuilder
{
    public function __construct(private StoliConfig $config) {}

    /**
     * @return list<ConstantGroup>
     */
    public function groups(): array
    {
        $paths = array_values(array_filter($this->config->constantsPaths(), is_dir(...)));
        $attributes = $this->config->constantsAttributes();

        if ($paths === [] || $attributes === []) {
            return [];
        }

        try {
            $classes = Discover::in(...$paths)
                ->classes()
                ->withAttribute(...$attributes)
                ->get();
        } catch (Throwable) {
            return [];
        }

        // Sorted so the generated file does not churn with the filesystem order.
        sort($classes);

        $groups = [];

        foreach ($classes as $class) {
            $group = $this->group($class);

            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function group(string $class): ?ConstantGroup
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return null;
        }

        // Enum cases are already exported as types by the typescript-transformer.
        if ($reflection->isEnum()) {
            return null;
        }

        $constants = [];

        foreach ($reflection->getReflectionConstants() as $constant) {
            if (! $constant->isPublic()) {
                continue;
            }

            if ($constant->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            try {
                $constants[$constant->getName()] = $constant->getValue();
            } catch (Throwable) {
                // A constant that cannot be evaluated is simply left out.
            }
        }

        if ($constants === []) {
            return null;
        }

        return new ConstantGroup(
            className: $reflection->getName(),
            namespace: array_values(array_filter(explode('\\', $reflection->getNamespaceName()))),
            name: $this->name($reflection),
            constants: $constants,
        );
    }

    private function name(ReflectionClass $reflection): string
    {
        foreach ($reflection->getAttributes(TypeScriptConstants::class) as $attribute) {
            try {
                $name = $attribute->newInstance()->name;
            } catch (Throwable) {
                continue;
            }

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return class_basename($reflection->getName());
    }
}
