<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;

final readonly class TypeScriptFileCompiler implements Compiler
{
    public function __construct(private JsonFileCompiler $jsonFileGenerator)
    {
    }

    public function compile(File $file): string
    {
        $module = Str::studly($file->name());

        return self::template(
            $module,
            self::jsonToTsFormat($this->jsonFileGenerator->compile($file)),
            self::generateParamsInterface($module, $file),
            self::generateResponseInterface($module, $file),
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

    private static function template(string $module, string $routes, string $paramsInterface, string $responseInterface, string $imports = ''): string
    {
        $middle = $responseInterface !== ''
            ? "$paramsInterface\n\n$responseInterface"
            : $paramsInterface;

        $importBlock = $imports !== '' ? "$imports\n\n" : '';

        return <<<TS
        {$importBlock}const routes = $routes as const;

        $middle

        export type {$module}RouteName = keyof {$module}RouteParams;

        export default routes;

        TS;
    }

    private static function generateParamsInterface(string $module, File $file): string
    {
        $entries = $file->routes()->reduce(function (array $acc, Route $route): array {
            $uriParams  = self::extractUriParams($route->uri(), $route->wheres());
            $formParams = $route->params() ?? [];
            // FormRequest params take precedence over URI params with the same name
            $merged = array_merge($uriParams, $formParams);

            $acc[$route->name()] = "\t'{$route->name()}': " . self::buildParamType($merged) . ';';
            return $acc;
        }, []);

        $body = implode("\n", $entries);

        return "export interface {$module}RouteParams {\n$body\n}";
    }

    private static function extractUriParams(string $uri, array $wheres = []): array
    {
        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        $params = [];
        foreach ($matches as $match) {
            $name = $match[1];
            $constraint = $wheres[$name] ?? null;
            $params[$name] = [
                'type'     => $constraint !== null ? self::constraintToType($constraint) : 'string | number',
                'required' => empty($match[2]),
            ];
        }

        return $params;
    }

    private static function constraintToType(string $regex): string
    {
        // Exact patterns produced by Laravel's named constraint helpers
        return match ($regex) {
            '[0-9]+'                                                                                   => 'number',
            '[a-zA-Z]+'                                                                                => 'string',
            '[a-zA-Z0-9]+'                                                                             => 'string',
            '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}'                   => 'string', // whereUuid
            '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}'                                                 => 'string', // whereUlid
            default                                                                                    => self::inferConstraintType($regex),
        };
    }

    private static function inferConstraintType(string $regex): string
    {
        // Simple literal alternation (e.g. "users|groups|all", produced by whereIn) → union of string literals
        if (preg_match('/^[a-zA-Z0-9_-]+(\|[a-zA-Z0-9_-]+)*$/', $regex)) {
            return implode(' | ', array_map(
                fn(string $v) => "'{$v}'",
                explode('|', $regex),
            ));
        }

        // Generic numeric-only pattern (e.g. "\d+", "[1-9][0-9]*") → number
        $anchored = "/^(?:{$regex})$/";
        if (
            @preg_match($anchored, '') !== false
            && preg_match($anchored, '123') === 1
            && preg_match($anchored, 'abc') === 0
        ) {
            return 'number';
        }

        return 'string';
    }

    private static function generateResponseInterface(string $module, File $file): string
    {
        $entries = $file->routes()->reduce(function (array $acc, Route $route): array {
            $response = $route->response();

            if ($response === null) {
                return $acc;
            }

            if (isset($response['typescript_type'])) {
                $inner = $response['collection']
                    ? $response['typescript_type'] . '[]'
                    : $response['typescript_type'];

                if ($response['wrap'] !== null) {
                    $inner = "{ {$response['wrap']}: $inner }";
                }
            } else {
                $shape = self::buildShapeType($response['shape']);
                $inner = self::wrapShape($shape, $response['collection'], $response['wrap']);
            }

            $acc[] = "\t'{$route->name()}': $inner;";

            return $acc;
        }, []);

        $body = implode("\n", $entries);

        return "export interface {$module}RouteResponse {\n$body\n}";
    }

    private static function buildShapeType(array $shape): string
    {
        if (empty($shape)) {
            return 'Record<string, unknown>';
        }

        $parts = [];

        foreach ($shape as $key => $type) {
            $parts[] = "$key: $type";
        }

        return '{ ' . implode('; ', $parts) . ' }';
    }

    private static function wrapShape(string $shape, bool $collection, ?string $wrap): string
    {
        $inner = $collection ? $shape . '[]' : $shape;

        return $wrap !== null ? "{ $wrap: $inner }" : $inner;
    }

    private static function buildParamType(array $params): string
    {
        if (empty($params)) {
            return 'Record<string, unknown>';
        }

        $properties = [];

        foreach ($params as $name => $info) {
            if ($name === '*') {
                continue; // top-level wildcard is covered by the index signature below
            }
            $optional = $info['required'] ? '' : '?';
            $properties[] = "$name$optional: {$info['type']}";
        }

        $properties[] = '[key: string]: unknown';

        return '{ ' . implode('; ', $properties) . ' }';
    }

    /**
     * Build an import block for any TypeScript type references collected from routes.
     * Groups types by their source file and emits one `import type` statement per file.
     */
    private static function collectTypeScriptImports(File $file): string
    {
        $byFile = $file->routes()->reduce(function (array $acc, Route $route): array {
            $response = $route->response();

            if (isset($response['typescript_type'], $response['typescript_file'])) {
                $acc[$response['typescript_file']][] = $response['typescript_type'];
            }

            return $acc;
        }, []);

        if (empty($byFile)) {
            return '';
        }

        $fromDir = base_path(rtrim($file->path(), '/'));
        $lines   = [];

        foreach ($byFile as $absFile => $types) {
            $rel   = self::relativeImportPath($fromDir, $absFile);
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

        $from = array_values(array_filter(explode('/', $fromDir), fn($p) => $p !== ''));
        $to   = array_values(array_filter(explode('/', $toFile), fn($p) => $p !== ''));

        $common = 0;
        $max    = min(count($from), count($to));

        while ($common < $max && $from[$common] === $to[$common]) {
            $common++;
        }

        $ups   = count($from) - $common;
        $downs = array_slice($to, $common);
        $parts = [...array_fill(0, $ups, '..'), ...$downs];
        $rel   = implode('/', $parts);

        if ($rel === '') {
            return '.';
        }

        return str_starts_with($rel, '.') ? $rel : './' . $rel;
    }
}
