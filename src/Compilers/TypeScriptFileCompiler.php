<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Utils;

final readonly class TypeScriptFileCompiler
{
    /**
     * The HTTP methods the Stoli axios wrapper exposes. Laravel pairs every GET
     * route with HEAD, so HEAD needs no type of its own.
     */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private ConstraintTypeMapper $constraintTypeMapper = new ConstraintTypeMapper,
    ) {}

    public function compile(File $file): string
    {
        $module = Str::studly($file->name());
        $imports = self::imports($file);
        $importBlock = $imports !== '' ? "{$imports}\n\n" : '';
        $routes = $this->routes($file);
        $params = $this->paramsInterface($module, $file);
        $responses = self::responseInterface($module, $file);
        $methodNames = self::methodNameTypes($module, $file);

        return <<<TS
        {$importBlock}const routes = {$routes} as const;

        {$params}

        {$responses}

        export type {$module}RouteName = keyof {$module}RouteParams;

        {$methodNames}
        export default routes;

        TS;
    }

    private function routes(File $file): string
    {
        $entries = [];

        foreach ($file->routes() as $route) {
            $host = $route->host();

            $entries[$route->name()] = TypeScript::object([
                'host' => $host === null ? 'null' : TypeScript::string($host),
                'uri' => TypeScript::string($route->uri()),
            ], 1);
        }

        return TypeScript::object($entries);
    }

    private function paramsInterface(string $module, File $file): string
    {
        $types = [];

        foreach ($file->routes() as $route) {
            $types[] = [$route->name(), $this->paramType($route)];
        }

        return self::interface("{$module}RouteParams", $types);
    }

    private static function responseInterface(string $module, File $file): string
    {
        $types = [];

        foreach ($file->routes() as $route) {
            $response = $route->dataResponseType();

            if ($response !== null) {
                $types[] = [$route->name(), $response->type];
            }
        }

        return self::interface("{$module}RouteResponse", $types);
    }

    /**
     * @param  list<array{string, string}>  $types  route name and type pairs
     */
    private static function interface(string $name, array $types): string
    {
        $lines = array_map(
            static fn (array $type): string => "\t".TypeScript::string($type[0]).": {$type[1]};",
            $types
        );

        return "export interface {$name} {\n".implode("\n", $lines)."\n}";
    }

    /**
     * The URI parameters, intersected with the Data request type when there is one.
     */
    private function paramType(Route $route): string
    {
        $parameters = $this->uriParameters($route);
        $data = $route->dataRequestType()?->type;

        if ($data === null) {
            return self::parametersType($parameters);
        }

        return $parameters === [] ? $data : self::parametersType($parameters)." & {$data}";
    }

    /**
     * The parameters in the route's host and path, with the TypeScript type their
     * constraint maps to.
     *
     * @return array<string, array{type: string, required: bool}>
     */
    private function uriParameters(Route $route): array
    {
        preg_match_all('/\{(\w+)(\?)?\}/', ($route->host() ?? '').'/'.$route->uri(), $matches, PREG_SET_ORDER);

        $parameters = [];

        foreach ($matches as $match) {
            $constraint = $route->wheres()[$match[1]] ?? null;

            $parameters[$match[1]] = [
                'type' => $constraint !== null ? $this->constraintTypeMapper->map($constraint) : 'string | number',
                'required' => ($match[2] ?? '') === '',
            ];
        }

        return $parameters;
    }

    /**
     * @param  array<string, array{type: string, required: bool}>  $parameters
     */
    private static function parametersType(array $parameters): string
    {
        if ($parameters === []) {
            return 'Record<string, unknown>';
        }

        $properties = [];

        foreach ($parameters as $name => $parameter) {
            $optional = $parameter['required'] ? '' : '?';
            $properties[] = "{$name}{$optional}: {$parameter['type']}";
        }

        $properties[] = '[key: string]: unknown';

        return '{ '.implode('; ', $properties).' }';
    }

    private static function methodNameTypes(string $module, File $file): string
    {
        $lines = [];

        foreach (self::METHODS as $method) {
            $names = $file->routes()
                ->filter(static fn (Route $route): bool => in_array($method, array_map(strtoupper(...), $route->methods()), true))
                ->map(static fn (Route $route): string => TypeScript::string($route->name()))
                ->all();

            $lines[] = "export type {$module}".ucfirst(strtolower($method)).'RouteName = '.TypeScript::union(array_values($names)).';';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Build an import block for the Data types the routes reference.
     *
     * Types from a `declare namespace` output file are ambient globals referenced by
     * their dotted namespace path (e.g. `App.Http.Data.StoreUserRequestData`) and
     * carry nothing to import.
     */
    private static function imports(File $file): string
    {
        $path = $file->path();

        if ($path === null) {
            return '';
        }

        $byFile = [];

        foreach ($file->routes() as $route) {
            foreach (array_filter([$route->dataRequestType(), $route->dataResponseType()]) as $dataType) {
                foreach ($dataType->imports as $name) {
                    $byFile[$dataType->file][$name] = $name;
                }
            }
        }

        $fromDir = Utils::absolutePath(rtrim($path, '/'));
        $lines = [];

        foreach ($byFile as $typesFile => $names) {
            $lines[] = 'import type { '.implode(', ', $names)." } from '".Utils::relativeImportPath($fromDir, $typesFile)."';";
        }

        return implode("\n", $lines);
    }
}
