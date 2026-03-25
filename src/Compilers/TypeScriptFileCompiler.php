<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;

final readonly class TypeScriptFileCompiler implements Compiler
{
    public function __construct(
        private JsonFileCompiler $jsonFileGenerator,
        private ConstraintTypeMapper $constraintTypeMapper = new ConstraintTypeMapper(),
    ) {}

    public function compile(File $file): string
    {
        $module = Str::studly($file->name());

        return self::template(
            $module,
            self::jsonToTsFormat($this->jsonFileGenerator->compile($file)),
            $this->generateParamsInterface($module, $file),
            self::generateResponseInterface($module, $file),
            self::generateMethodNameTypes($module, $file),
            self::collectTypeScriptImports($file),
        );
    }

    private static function jsonToTsFormat(string $string): string
    {
        return preg_replace("/'([a-zA-Z_][a-zA-Z0-9_]*)'\s*:/", '$1:', preg_replace("/(')\s*$/m", "',", str_replace('"', "'", $string)));
    }

    public function extension(): string
    {
        return 'ts';
    }

    private static function template(string $module, string $routes, string $paramsInterface, string $responseInterface, string $methodNameTypes, string $imports = ''): string
    {
        $middle = "$paramsInterface\n\n$responseInterface";

        $importBlock = $imports !== '' ? "$imports\n\n" : '';

        return <<<TS
        {$importBlock}const routes = $routes as const;

        $middle

        export type {$module}RouteName = keyof {$module}RouteParams;

        $methodNameTypes
        export default routes;

        TS;
    }

    private function generateParamsInterface(string $module, File $file): string
    {
        $entries = $file->routes()->reduce(function (array $acc, Route $route): array {
            $acc[$route->name()] = "\t'{$route->name()}': " . $this->buildParamTypeForRoute($route) . ';';

            return $acc;
        }, []);

        $body = implode("\n", $entries);

        return "export interface {$module}RouteParams {\n$body\n}";
    }

    private function buildParamTypeForRoute(Route $route): string
    {
        $uriParams = $this->extractUriParams($route->uri(), $route->wheres());
        $dataRequest = $route->dataRequestType();

        // When there is a Data request type, URI params that match a field in the
        // Data class are dropped — the Data type takes precedence.  Remaining URI
        // params (e.g. path-only identifiers not present in the body) are expressed
        // as an inline object intersected with the Data type.
        if ($dataRequest !== null) {
            if (empty($uriParams)) {
                return $dataRequest['type'];
            }

            $uriBlock = self::buildUriParamType($uriParams);

            return "{$uriBlock} & {$dataRequest['type']}";
        }

        // No Data type — fall back to URI params only.
        return self::buildUriParamType($uriParams);
    }

    private function extractUriParams(string $uri, array $wheres = []): array
    {
        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        $params = [];
        foreach ($matches as $match) {
            $name = $match[1];
            $constraint = $wheres[$name] ?? null;
            $params[$name] = [
                'type' => $constraint !== null
                    ? $this->constraintTypeMapper->map($constraint)
                    : 'string | number',
                'required' => empty($match[2]),
            ];
        }

        return $params;
    }

    private static function buildUriParamType(array $params): string
    {
        if (empty($params)) {
            return 'Record<string, unknown>';
        }

        $properties = [];

        foreach ($params as $name => $info) {
            $optional = $info['required'] ? '' : '?';
            $properties[] = "$name$optional: {$info['type']}";
        }

        $properties[] = '[key: string]: unknown';

        return '{ ' . implode('; ', $properties) . ' }';
    }

    private static function generateResponseInterface(string $module, File $file): string
    {
        $entries = $file->routes()->reduce(function (array $acc, Route $route): array {
            $dataResponse = $route->dataResponseType();

            if ($dataResponse === null) {
                return $acc;
            }

            $acc[] = "\t'{$route->name()}': {$dataResponse['type']};";

            return $acc;
        }, []);

        $body = implode("\n", $entries);

        return "export interface {$module}RouteResponse {\n$body\n}";
    }

    private static function generateMethodNameTypes(string $module, File $file): string
    {
        // The HTTP methods we expose on the Stoli axios wrapper.
        // Laravel always includes HEAD alongside GET; we treat HEAD as GET for routing purposes.
        $supported = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        // Collect the set of route names that respond to each HTTP method.
        $byMethod = array_fill_keys($supported, []);

        $file->routes()->each(function (Route $route) use (&$byMethod, $supported): void {
            // Normalise: HEAD is covered by GET in the wrapper.
            $methods = array_map('strtoupper', $route->methods());
            if (in_array('HEAD', $methods, true) && !in_array('GET', $methods, true)) {
                $methods[] = 'GET';
            }

            foreach ($supported as $method) {
                if (in_array($method, $methods, true)) {
                    $byMethod[$method][] = "'{$route->name()}'";
                }
            }
        });

        $lines = [];
        foreach ($supported as $method) {
            $names = $byMethod[$method];
            $studlyMethod = ucfirst(strtolower($method));

            if (empty($names)) {
                $union = 'never';
            } else {
                $union = implode(' | ', $names);
            }

            $lines[] = "export type {$module}{$studlyMethod}RouteName = $union;";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Build an import block for any TypeScript type references collected from routes.
     *
     * Only emits `import type` statements for non-ambient types (i.e. types that live
     * in regular ES module files with top-level `export type` declarations).
     *
     * Types that come from `declare namespace` output files are ambient globals and
     * must not be imported — they are referenced directly by their dotted namespace
     * path (e.g. `App.Http.Data.StoreUserRequestData`).
     */
    private static function collectTypeScriptImports(File $file): string
    {
        $byFile = $file->routes()->reduce(function (array $acc, Route $route): array {
            foreach (['dataRequestType', 'dataResponseType'] as $getter) {
                $typeInfo = $route->$getter();
                // Skip ambient types — they are declare namespace globals, no import needed.
                if ($typeInfo !== null && !$typeInfo['ambient']) {
                    $acc[$typeInfo['file']][] = $typeInfo['type'];
                }
            }

            return $acc;
        }, []);

        if (empty($byFile)) {
            return '';
        }

        $fromDir = base_path(rtrim($file->path(), '/'));
        $lines = [];

        foreach ($byFile as $absFile => $types) {
            $rel = self::relativeImportPath($fromDir, $absFile);
            $names = implode(', ', array_unique($types));
            $lines[] = "import type { $names } from '$rel';";
        }

        return implode("\n", $lines);
    }

    /**
     * Compute the relative import path from a directory to an absolute file path,
     * stripping .d.ts / .ts extensions (TypeScript resolves them automatically).
     */
    private static function relativeImportPath(string $fromDir, string $toFile): string
    {
        // Strip TS extensions — TypeScript resolves the file without them
        $toFile = preg_replace('/\.d\.ts$|\.ts$/', '', $toFile);

        $from = array_values(array_filter(explode('/', $fromDir), fn ($p) => $p !== ''));
        $to = array_values(array_filter(explode('/', $toFile), fn ($p) => $p !== ''));

        $common = 0;
        $max = min(count($from), count($to));

        while ($common < $max && $from[$common] === $to[$common]) {
            $common++;
        }

        $ups = count($from) - $common;
        $downs = array_slice($to, $common);
        $parts = [...array_fill(0, $ups, '..'), ...$downs];
        $rel = implode('/', $parts);

        if ($rel === '') {
            return '.';
        }

        return str_starts_with($rel, '.') ? $rel : './' . $rel;
    }
}
