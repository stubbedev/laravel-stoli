<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Closure;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router as LaravelRouter;
use StubbeDev\LaravelStoli\Items\{File, Module};
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Support\AbstractList;
use StubbeDev\LaravelStoli\Support\ArrayList;

final readonly class FileRouteBuilder
{
    private ArrayList $laravelRoutes;

    public function __construct(
        LaravelRouter                    $router,
        private ModulesProvider          $provider,
        private RouteMatcher             $matcher,
        private SpatieDataTypeResolver   $dataTypeResolver,
    )
    {
        $this->laravelRoutes = new ArrayList($router->getRoutes()->getRoutes());
    }

    public function files(): ArrayList
    {
        $routes          = $this->laravelRoutes->filter($this->onlyNamed());
        $modules         = $this->provider->modules();
        $matches         = $modules->matches();
        $routeCollection = $matches->reduce($this->pair($routes), new ArrayList(AbstractList::Empty));

        return $this->provider
            ->modules()
            ->map($this->toRoutesFile($routeCollection));
    }

    private function onlyNamed(): Closure
    {
        return fn(LaravelRoute $route): bool => $route->getName() != null;
    }

    private function pair(ArrayList $routes): callable
    {
        return function (ArrayList $acc, string $match) use ($routes) {
            $filtered = $routes->filter(
                fn(LaravelRoute $route) => $this->matcher->matches($route->uri(), $match)
            );

            $acc->push($filtered, $match);
            return $acc;
        };
    }

    private function toRoutesFile(ArrayList $routeCollection): callable
    {
        return function (Module $module) use ($routeCollection): File {
            $routes = $routeCollection->pick(self::matchedWith($module));

            return File::from(
                $module,
                $routes->map($this->createRouteFor($module))
            );
        };
    }

    private function createRouteFor(Module $module): Closure
    {
        return function (LaravelRoute $route) use ($module): Route {
            $resolved = $this->dataTypeResolver->resolve($route);

            return new Route(
                name:             $route->getName(),
                rootUrl:          $module->rootUrl(),
                uri:              $route->uri(),
                prefix:           $module->prefix(),
                absolute:         $module->absolute(),
                host:             $route->domain(),
                wheres:           $route->wheres,
                methods:          $route->methods(),
                stripPrefix:      $module->stripPrefix(),
                dataRequestType:  $resolved['request'],
                dataResponseType: $resolved['response'],
            );
        };
    }

    private static function matchedWith(Module $module): callable
    {
        return static fn(ArrayList $routes, string $prefix) => $prefix === $module->match();
    }
}
