<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use StubbeDev\LaravelStoli\Exporters\AxiosRouterExporter;
use StubbeDev\LaravelStoli\Exporters\RouteServiceExporter;
use StubbeDev\LaravelStoli\Exporters\RoutesFileExporter;

final readonly class Publisher
{
    public function __construct(
        private RouteServiceExporter $routeServiceExporter,
        private RoutesFileExporter $routesFileExporter,
        private AxiosRouterExporter $axiosRouterExporter,
    ) {}

    public function publish(): void
    {
        $this->routeServiceExporter->publish();
        $this->routesFileExporter->publish();
        $this->axiosRouterExporter->publish();
    }
}
