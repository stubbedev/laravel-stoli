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
use StubbeDev\LaravelStoli\ReturnTypeResolver;

final readonly class FileRouteBuilder
{
    private ArrayList $laravelRoutes;

    public function __construct(
        LaravelRouter                        $router,
        private ModulesProvider              $provider,
        private RouteMatcher                 $matcher,
        private RequestParameterResolver     $parameterResolver,
        private ReturnTypeResolver           $returnTypeResolver,
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
        return fn(LaravelRoute $route): Route => new Route(
            $route->getName(),
            $module->rootUrl(),
            $route->uri(),
            $module->prefix(),
            $module->absolute(),
            $route->domain(),
            $this->parameterResolver->resolve($route),
            $this->returnTypeResolver->resolve($route),
            $route->wheres,
            $route->methods(),
        );
    }

    private static function matchedWith(Module $module): callable
    {
        return static fn(ArrayList $routes, string $prefix) => $prefix === $module->match();
    }
}
