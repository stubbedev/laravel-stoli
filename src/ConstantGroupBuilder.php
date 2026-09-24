<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use ReflectionClass;
use Spatie\StructureDiscoverer\Discover;
use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;
use StubbeDev\LaravelStoli\Compilers\TypeScript;
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
     *
     * @throws StoliException when the configured directories cannot be scanned
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
        } catch (Throwable $error) {
            // Returning nothing here would leave the last constants file in place as if
            // it were current.
            throw StoliException::cantDiscoverConstants($error);
        }

        $classes = array_filter($classes, is_string(...));

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

    /**
     * @throws StoliException when a class publishes its constants under a name that is not an identifier
     */
    private function group(string $class): ?ConstantGroup
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

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

    /**
     * The key the constants are published under. It also names the union type, so
     * it has to be a TypeScript identifier.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function name(ReflectionClass $reflection): string
    {
        foreach ($reflection->getAttributes(TypeScriptConstants::class) as $attribute) {
            try {
                $name = $attribute->newInstance()->name;
            } catch (Throwable) {
                continue;
            }

            if ($name === null || $name === '') {
                continue;
            }

            if (! TypeScript::isIdentifier($name)) {
                throw StoliException::invalidConstantsName($reflection->getName(), $name);
            }

            return $name;
        }

        return class_basename($reflection->getName());
    }
}
