<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Routing\Route as LaravelRoute;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Resolves request and response TypeScript type references for controller actions
 * that use Spatie Laravel Data objects.
 *
 * When a controller method accepts a Data subclass as its request parameter, this
 * resolver looks up the corresponding TypeScript type in the typescript-transformer
 * output file and returns its name + source file for use in import statements.
 *
 * The same lookup is performed on the return type annotation for the response.
 *
 * If the output file uses `declare namespace` (the default when
 * spatie/typescript-transformer is configured with a namespace map), the types
 * are ambient globals and no import is needed.  In that case `ambient` is true
 * and `type` contains the fully-qualified dotted namespace path (e.g.
 * `App.Http.Data.StoreUserRequestData`).
 *
 * If the output file uses regular `export type` declarations, `ambient` is false
 * and `type` is just the class basename — the compiler will emit an `import type`
 * statement pointing at the output file.
 *
 * Routes that do not use Spatie Data objects return null for both fields.
 */
final readonly class SpatieDataTypeResolver
{
    /**
     * @return array{
     *     request: array{type: string, file: string, ambient: bool}|null,
     *     response: array{type: string, file: string, ambient: bool}|null,
     * }
     */
    public function resolve(LaravelRoute $route): array
    {
        $action = $route->getAction('uses');

        if (!is_string($action)) {
            return ['request' => null, 'response' => null];
        }

        if (str_contains($action, '@')) {
            [$controller, $method] = explode('@', $action, 2);
        } else {
            $controller = $action;
            $method     = '__invoke';
        }

        if (!class_exists($controller)) {
            return ['request' => null, 'response' => null];
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return ['request' => null, 'response' => null];
        }

        return [
            'request'  => $this->resolveRequestType($reflection),
            'response' => $this->resolveResponseType($reflection),
        ];
    }

    /**
     * Find a Spatie Data parameter on the method and look it up in the
     * typescript-transformer output.
     *
     * @return array{type: string, file: string, ambient: bool}|null
     */
    private function resolveRequestType(ReflectionMethod $method): ?array
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (!$this->isDataClass($className)) {
                continue;
            }

            return $this->lookupTransformerType($className);
        }

        return null;
    }

    /**
     * Inspect the return type annotation and look up a Data class in the
     * typescript-transformer output.
     *
     * @return array{type: string, file: string, ambient: bool}|null
     */
    private function resolveResponseType(ReflectionMethod $method): ?array
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType) {
            return null;
        }

        $className = $returnType->getName();

        if (!$this->isDataClass($className)) {
            return null;
        }

        return $this->lookupTransformerType($className);
    }

    private function isDataClass(string $className): bool
    {
        return class_exists('Spatie\\LaravelData\\Data')
            && class_exists($className)
            && is_subclass_of($className, 'Spatie\\LaravelData\\Data');
    }

    /**
     * Look up the given PHP class in the typescript-transformer output file.
     *
     * Handles two output formats produced by spatie/typescript-transformer:
     *
     *  1. `declare namespace` format (ambient globals):
     *     The file opens with `declare namespace …`.  Types are accessed via a
     *     dotted path derived from the PHP FQN (backslashes → dots).  No import
     *     is needed; `ambient` is set to true.
     *
     *  2. `export type` format (regular ES modules):
     *     The file uses top-level `export type Foo = …`.  The bare class basename
     *     is used as the type name and an `import type` statement is emitted.
     *     `ambient` is set to false.
     *
     * @return array{type: string, file: string, ambient: bool}|null
     */
    private function lookupTransformerType(string $dataClass): ?array
    {
        try {
            $outputFile = config('typescript-transformer.output_file');

            if (!is_string($outputFile) || !file_exists($outputFile)) {
                return null;
            }

            $content = @file_get_contents($outputFile);

            if ($content === false) {
                return null;
            }

            $resolvedFile = realpath($outputFile) ?: $outputFile;
            $baseName     = class_basename($dataClass);

            // Detect whether the file uses declare-namespace format.
            $isDeclareNamespace = (bool) preg_match('/^\s*declare\s+namespace\s+/m', $content);

            if ($isDeclareNamespace) {
                // Build the dotted namespace path from the PHP FQN.
                // e.g. App\Http\Data\StoreUserRequestData → App.Http.Data.StoreUserRequestData
                $dottedPath = str_replace('\\', '.', ltrim($dataClass, '\\'));

                // Verify the type exists in the file by matching the class basename
                // as an exported type/interface within a namespace block.
                if (!preg_match('/\bexport\s+(?:type|interface)\s+' . preg_quote($baseName, '/') . '\b/', $content)) {
                    return null;
                }

                return ['type' => $dottedPath, 'file' => $resolvedFile, 'ambient' => true];
            }

            // Regular export format — match by bare class basename.
            if (preg_match('/\bexport\s+(?:type|interface)\s+' . preg_quote($baseName, '/') . '\b/', $content)) {
                return ['type' => $baseName, 'file' => $resolvedFile, 'ambient' => false];
            }
        } catch (Throwable) {
        }

        return null;
    }
}
