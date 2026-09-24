<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Closure;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router as LaravelRouter;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Module;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Support\ArrayList;

final readonly class FileRouteBuilder
{
    private ArrayList $laravelRoutes;

    public function __construct(
        LaravelRouter $router,
        private ModulesProvider $provider,
        private RouteMatcher $matcher,
        private SpatieDataTypeResolver $dataTypeResolver,
    ) {
        $this->laravelRoutes = new ArrayList($router->getRoutes()->getRoutes());
    }

    public function files(): ArrayList
    {
        $routes = $this->laravelRoutes
            ->filter($this->onlyNamed())
            ->unique(fn (LaravelRoute $route) => $route->getName());

        return $this->provider
            ->modules()
            ->map(fn (Module $module): File => File::from(
                $module,
                $routes
                    ->filter($this->belongsTo($module))
                    ->map($this->createRouteFor($module))
            ));
    }

    private function belongsTo(Module $module): Closure
    {
        return fn (LaravelRoute $route): bool => $this->matcher->matches($route->uri(), $module->match())
            && $module->matchesName((string) $route->getName());
    }

    private function onlyNamed(): Closure
    {
        return function (LaravelRoute $route): bool {
            $name = $route->getName();

            return $name !== null && !str_ends_with($name, '.');
        };
    }

    private function createRouteFor(Module $module): Closure
    {
        return function (LaravelRoute $route) use ($module): Route {
            $resolved = $this->dataTypeResolver->resolve($route);

            return new Route(
                name: $route->getName(),
                rootUrl: $module->rootUrl(),
                uri: $route->uri(),
                prefix: $module->prefix(),
                absolute: $module->absolute(),
                host: $route->domain(),
                wheres: $route->wheres,
                methods: $route->methods(),
                stripPrefix: $module->stripPrefix(),
                dataRequestType: $resolved['request'],
                dataResponseType: $resolved['response'],
            );
        };
    }
}
