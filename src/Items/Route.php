<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Items;

use StubbeDev\LaravelStoli\Utils;

use function array_filter;

readonly class Route
{
    public function __construct(
        private string  $name,
        private string  $rootUrl,
        private string  $uri,
        private ?string $prefix,
        private bool    $absolute,
        private ?string $host,
        private array   $wheres = [],
        private array   $methods = [],
        private ?string $stripPrefix = null,
        /** @var array{type: string, file: string}|null */
        private ?array  $dataRequestType = null,
        /** @var array{type: string, file: string}|null */
        private ?array  $dataResponseType = null,
    )
    {
    }

    public function name(): string
    {
        if ($this->stripPrefix !== null && str_starts_with($this->name, $this->stripPrefix)) {
            return substr($this->name, strlen($this->stripPrefix));
        }

        return $this->name;
    }

    public function originalName(): string
    {
        return $this->name;
    }

    public function host(): ?string
    {
        return $this->absolute ? Utils::removeForwardSlashes($this->host ?? $this->rootUrl) : null;
    }

    public function uri(): string
    {
        $segments = array_filter([
            Utils::removeForwardSlashes($this->prefix),
            Utils::removeForwardSlashes($this->uri)
        ]);

        return implode('/', $segments);
    }

    public function wheres(): array
    {
        return $this->wheres;
    }

    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * @return array{type: string, file: string}|null
     */
    public function dataRequestType(): ?array
    {
        return $this->dataRequestType;
    }

    /**
     * @return array{type: string, file: string}|null
     */
    public function dataResponseType(): ?array
    {
        return $this->dataResponseType;
    }

    public function primitives(): array
    {
        return [
            'host' => $this->host(),
            'uri'  => $this->uri(),
        ];
    }
}
