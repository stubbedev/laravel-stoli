<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

final class StoliConfig
{
    public function __construct(private readonly array $config)
    {
    }

    public function libraryPath(): string
    {
        return $this->config['library'] ?? 'resources/routes';
    }

    public function splitModulesInFiles(): bool
    {
        return $this->config['split'] ?? true;
    }

    public function resourcesPath(): string
    {
        return $this->config['resources'];
    }

    public function modules(): array
    {
        return $this->config['modules'] ?? [];
    }

    public function defaultSingleFileModuleName(): string
    {
        return $this->config['single']['name'] ?? 'api';
    }

    public function defaultSingleFileOutputPath(): string
    {
        return $this->config['single']['path'] ?? $this->libraryPath();
    }

    public function axiosRouter(): bool
    {
        return $this->config['axios'] ?? false;
    }
}
