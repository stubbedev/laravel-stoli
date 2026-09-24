<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router as LaravelRouter;
use Illuminate\Support\Collection;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Module;
use StubbeDev\LaravelStoli\Items\Route;

final readonly class FileRouteBuilder
{
    public function __construct(
        private LaravelRouter $router,
        private ModulesProvider $provider,
        private RouteMatcher $matcher,
        private SpatieDataTypeResolver $dataTypeResolver,
    ) {}

    /**
     * @return Collection<int, File>
     */
    public function files(): Collection
    {
        $routes = (new Collection($this->router->getRoutes()->getRoutes()))
            ->filter(self::isNamed(...))
            ->unique(static fn (LaravelRoute $route): string => (string) $route->getName())
            ->values();

        return $this->provider
            ->modules()
            ->map(fn (Module $module): File => File::from(
                $module,
                $routes
                    ->filter(fn (LaravelRoute $route): bool => $this->belongsTo($route, $module))
                    ->map(fn (LaravelRoute $route): Route => $this->createRoute($route, $module))
                    // A stripped prefix can fold two route names into one.
                    ->unique(static fn (Route $route): string => $route->name())
                    ->values()
            ));
    }

    private function belongsTo(LaravelRoute $route, Module $module): bool
    {
        return $this->matcher->matches($route->uri(), $module->match())
            && $module->matchesName((string) $route->getName());
    }

    /**
     * Group names Laravel leaves on unnamed routes end with a dot and are skipped too.
     */
    private static function isNamed(LaravelRoute $route): bool
    {
        $name = $route->getName();

        return $name !== null && $name !== '' && ! str_ends_with($name, '.');
    }

    /**
     * @return array<string, string>
     */
    private static function wheres(LaravelRoute $route): array
    {
        $wheres = [];

        foreach ($route->wheres as $parameter => $constraint) {
            if (is_string($parameter) && is_string($constraint)) {
                $wheres[$parameter] = $constraint;
            }
        }

        return $wheres;
    }

    private function createRoute(LaravelRoute $route, Module $module): Route
    {
        $resolved = $this->dataTypeResolver->resolve($route);
        $domain = $route->getDomain();

        return new Route(
            name: (string) $route->getName(),
            rootUrl: $module->rootUrl(),
            uri: $route->uri(),
            prefix: $module->prefix(),
            absolute: $module->absolute(),
            host: is_string($domain) ? $domain : null,
            wheres: self::wheres($route),
            methods: array_values(array_filter($route->methods(), is_string(...))),
            stripPrefix: $module->stripPrefix(),
            dataRequestType: $resolved['request'],
            dataResponseType: $resolved['response'],
        );
    }
}
