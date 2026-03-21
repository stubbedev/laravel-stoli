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
        private ?array  $params = null,
        private ?array  $response = null,
        private array   $wheres = [],
    )
    {
    }

    public function name(): string
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

    public function params(): ?array
    {
        return $this->params;
    }

    public function response(): ?array
    {
        return $this->response;
    }

    public function wheres(): array
    {
        return $this->wheres;
    }

    public function primitives(): array
    {
        return [
            'host' => $this->host(),
            'uri'  => $this->uri(),
        ];
    }
}
