<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router as LaravelRouter;
use StubbeDev\LaravelStoli\Compilers\ConstraintTypeMapper;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Module;
use StubbeDev\LaravelStoli\Items\Parameter;
use StubbeDev\LaravelStoli\Items\Route;

/**
 * Builds the route files: the named routes of each module, every one of them published
 * the way its module says. With `split` off, the modules that are not standalone are
 * combined into the single configured file.
 */
final readonly class FileRouteBuilder
{
    public function __construct(
        private LaravelRouter $router,
        private ModulesProvider $provider,
        private SpatieDataTypeResolver $types,
        private StoliConfig $config,
        private ConstraintTypeMapper $constraints = new ConstraintTypeMapper,
    ) {}

    /**
     * The files to write. A module without a directory to write to has none.
     *
     * @return list<File>
     */
    public function files(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            // Group names Laravel leaves on unnamed routes end with a dot and are skipped too.
            if ($name !== null && $name !== '' && ! str_ends_with($name, '.')) {
                $routes[$name] ??= $route;
            }
        }

        $files = [];
        $combined = [];

        foreach ($this->provider->modules() as $module) {
            $published = [];

            foreach ($routes as $name => $route) {
                if ($module->matches($route->uri(), (string) $name)) {
                    // A stripped prefix can fold two route names into one; the first one stays.
                    $published[$module->routeName((string) $name)] ??= $route;
                }
            }

            $built = array_map(fn (string $name, LaravelRoute $route): Route => $this->route($name, $route, $module), array_keys($published), $published);

            if ($module->standalone || $this->config->splitModulesInFiles()) {
                $files[] = $this->file($module->name, $module->path, $built, $module->standalone);
            } else {
                $combined[] = $built;
            }
        }

        if ($combined !== []) {
            $unique = [];

            foreach (array_merge(...$combined) as $route) {
                $unique[$route->name] ??= $route;
            }

            array_unshift($files, $this->file($this->config->defaultSingleFileModuleName(), $this->config->defaultOutputPath(), array_values($unique)));
        }

        return array_values(array_filter($files));
    }

    /**
     * @param  list<Route>  $routes
     */
    private function file(string $name, ?string $directory, array $routes, bool $standalone = false): ?File
    {
        return $directory === null
            ? null
            : new File($name, $directory, $this->config->runtimeDirectory($directory), $routes, $standalone);
    }

    private function route(string $name, LaravelRoute $route, Module $module): Route
    {
        $domain = $route->getDomain();
        $domain = is_string($domain) && $domain !== '' ? $domain : null;
        $methods = array_values(array_intersect(Route::METHODS, array_map(strtolower(...), $route->methods())));

        return new Route(
            name: $name,
            uri: $module->uri($route->uri()),
            host: $module->host($domain),
            domain: $domain !== null,
            parameters: $this->parameters($route),
            methods: $methods,
            request: $this->types->request($route),
            response: $this->types->response($route),
            status: $this->types->status($route, $methods),
        );
    }

    /**
     * The placeholders in the route's domain and path, as Laravel reads them, typed by
     * their `where` constraints or else by the action's signature.
     *
     * @return list<Parameter>
     */
    private function parameters(LaravelRoute $route): array
    {
        $optional = $route->getOptionalParameterNames();
        $signature = $this->types->parameters($route);
        $parameters = [];

        foreach (array_filter($route->parameterNames(), is_string(...)) as $name) {
            $constraint = $route->wheres[$name] ?? null;

            $parameters[$name] ??= new Parameter(
                name: $name,
                // A constraint says what the URL takes; without one, the action's signature does.
                type: is_string($constraint) ? $this->constraints->map($constraint) : $signature[$name] ?? $this->constraints->map(null),
                required: ! array_key_exists($name, $optional),
            );
        }

        return array_values($parameters);
    }
}
