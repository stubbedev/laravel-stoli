<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use StubbeDev\LaravelStoli\Items\Module;
use StubbeDev\LaravelStoli\Support\ArrayList;

use function config;

final readonly class ModulesProvider
{
    public function __construct(private StoliConfig $config) {}

    public function modules(): Modules
    {
        $modules = new ArrayList($this->config->modules());
        $this->validateDuplicates($modules);

        return new Modules(
            $modules->map($this->createModule())
        );
    }

    private function validateDuplicates(ArrayList $modules): void
    {
        $modules->duplicates(self::moduleName())
            ->some(self::throwException());
    }

    private function createModule(): callable
    {
        return fn (array $config) => new Module(
            $config['match'] ?? '*',
            $config['rootUrl'] ?? config('app.url'),
            $config['name'],
            $config['prefix'] ?? '',
            $config['path'] ?? $this->config->libraryPath(),
            $config['absolute'] ?? true,
            $config['stripPrefix'] ?? null,
        );
    }

    private static function moduleName(): callable
    {
        return static fn (array $config) => $config['name'];
    }

    private static function throwException(): callable
    {
        return static fn (string $name) => throw StoliException::moduleAlreadyExists($name);
    }
}
