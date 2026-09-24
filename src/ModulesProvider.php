<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Support\Collection;
use StubbeDev\LaravelStoli\Items\Module;

use function config;

final readonly class ModulesProvider
{
    public function __construct(private StoliConfig $config) {}

    /**
     * @return Collection<int, Module>
     *
     * @throws StoliException when a module is malformed or its name is taken
     */
    public function modules(): Collection
    {
        $modules = [];

        foreach ($this->config->modules() as $index => $definition) {
            $module = $this->module($index, $definition);

            if (isset($modules[$module->name()])) {
                throw StoliException::moduleAlreadyExists($module->name());
            }

            $modules[$module->name()] = $module;
        }

        return new Collection(array_values($modules));
    }

    private function module(int|string $index, mixed $definition): Module
    {
        if (! is_array($definition)) {
            throw StoliException::invalidModule($index, 'a module is an array of options');
        }

        $name = self::string($index, $definition, 'name');

        if ($name === null) {
            throw StoliException::invalidModule($index, 'the "name" option is required');
        }

        return new Module(
            match: self::string($index, $definition, 'match') ?? '*',
            rootUrl: self::string($index, $definition, 'rootUrl') ?? self::appUrl(),
            name: $name,
            prefix: self::string($index, $definition, 'prefix') ?? '',
            path: self::string($index, $definition, 'path') ?? $this->config->defaultOutputPath(),
            absolute: (bool) ($definition['absolute'] ?? true),
            stripPrefix: self::string($index, $definition, 'stripPrefix'),
            names: self::names($index, $definition['names'] ?? null),
            standalone: (bool) ($definition['standalone'] ?? false),
        );
    }

    /**
     * An optional string option: null when absent or empty, rejected when it holds anything else.
     *
     * @param  array<mixed>  $definition
     */
    private static function string(int|string $index, array $definition, string $key): ?string
    {
        $value = $definition[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw StoliException::invalidModule($index, "the \"$key\" option must be a string");
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>|null
     */
    private static function names(int|string $index, mixed $names): ?array
    {
        if ($names === null) {
            return null;
        }

        $patterns = [];

        foreach (is_array($names) ? $names : [$names] as $name) {
            if (! is_string($name)) {
                throw StoliException::invalidModule($index, 'the "names" option must be a pattern or a list of patterns');
            }

            $patterns[] = $name;
        }

        return $patterns;
    }

    private static function appUrl(): string
    {
        $url = config('app.url');

        return is_string($url) ? $url : '';
    }
}
