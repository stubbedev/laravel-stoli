<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Utils;

use function Illuminate\Filesystem\join_paths;

/**
 * Compiles a route file: the input types its requests take, one type holding every
 * route's definition (its parameters, response, status and HTTP methods), the aliases
 * derived from it, and the routes themselves,
 * typed by that definition. Whatever is built from the routes - the route service, the
 * axios router - reads its types from them, so nothing can disagree with the PHP side.
 */
final readonly class TypeScriptFileCompiler
{
    public function compile(File $file): string
    {
        $module = Str::studly($file->name);
        $definitions = "{$module}Routes";
        $referenced = array_values(array_filter(array_merge(...array_map(static fn (Route $route): array => [$route->request, $route->response], $file->routes))));
        $imports = self::imports($file, [
            DataType::runtime('NamesFor'),
            DataType::runtime('ParamsOf'),
            DataType::runtime('ResponsesOf'),
            DataType::runtime('RouteMap'),
            DataType::runtime('StatusesOf'),
            ...$referenced,
        ]);
        $declarations = DataType::declarations(...$referenced);
        ksort($declarations);
        $declared = $declarations === [] ? '' : implode("\n\n", $declarations)."\n\n";

        $types = [];
        $entries = [];

        foreach ($file->routes as $route) {
            $types[$route->name] = TypeScript::object([
                'params' => self::params($route)->type,
                'response' => $route->response->type ?? 'unknown',
                'status' => $route->status,
                'methods' => TypeScript::union(array_map(TypeScript::string(...), $route->methods)),
            ], 1);

            $entries[$route->name] = self::route($route, 1);
        }

        $methods = implode("\n", array_map(
            static fn (string $method): string => "export type {$module}".ucfirst($method)."RouteName = NamesFor<{$definitions}, '{$method}'>;",
            Route::METHODS,
        ));

        $routes = TypeScript::object($entries);
        $definition = TypeScript::object($types);

        return <<<TS
        {$imports}

        {$declared}export type {$definitions} = {$definition};

        export type {$module}RouteName = keyof {$definitions};
        export type {$module}RouteParams = ParamsOf<{$definitions}>;
        export type {$module}RouteResponse = ResponsesOf<{$definitions}>;
        export type {$module}RouteStatus = StatusesOf<{$definitions}>;
        {$methods}

        const routes: RouteMap<{$definitions}> = {$routes};

        export default routes;

        TS;
    }

    /**
     * Where a route is reached, as the route service reads it.
     */
    public static function route(Route $route, int $depth = 0): string
    {
        return TypeScript::object(array_filter([
            'host' => $route->host === null ? 'null' : TypeScript::string($route->host),
            'uri' => TypeScript::string($route->uri),
            // Tells the route service not to swap this host for a rootUrl.
            'domain' => $route->domain ? 'true' : null,
        ]), $depth);
    }

    /**
     * The path and domain parameters, with the Data request type when there is one.
     * Without one, nothing says what else the route takes, so any other parameter goes
     * out as the query string or body.
     */
    private static function params(Route $route): DataType
    {
        $properties = [];
        $optional = [];

        foreach ($route->parameters as $parameter) {
            // An optional parameter left out or null takes its path segment with it.
            $properties[$parameter->name] = new DataType($parameter->required ? $parameter->type : "{$parameter->type} | null");

            if (! $parameter->required) {
                $optional[] = $parameter->name;
            }
        }

        if ($route->request === null) {
            return DataType::object($properties, $optional, open: true);
        }

        return $properties === [] ? $route->request : DataType::object($properties, $optional)->and($route->request);
    }

    /**
     * The import block for the types the file references, each from its own file.
     *
     * @param  list<DataType>  $types
     */
    private static function imports(File $file, array $types): string
    {
        $from = Utils::absolutePath($file->directory);
        $lines = [];

        foreach (DataType::merge(...$types) as $source => $names) {
            $source = $source === DataType::RUNTIME ? join_paths(Utils::absolutePath($file->runtime), 'stoli') : $source;
            $lines[] = 'import type { '.implode(', ', $names)." } from '".Utils::relativeImportPath($from, $source)."';";
        }

        return implode("\n", $lines);
    }
}
