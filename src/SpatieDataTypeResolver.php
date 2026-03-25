<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Routing\Route as LaravelRoute;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
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
            $method = '__invoke';
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
            'request' => $this->resolveRequestType($reflection),
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
     * When the reflected return type is a wrapper Data class (e.g. ApiResponseData),
     * the PHPDoc @return tag is parsed for a generic argument so the inner type
     * (e.g. StoreUserResponseData in ApiResponseData<StoreUserResponseData>) is
     * resolved instead, producing a fully-qualified TS type like
     * App.Http.Data.ApiResponseData<App.Http.Data.StoreUserResponseData>.
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

        $resolved = $this->lookupTransformerType($className);

        if ($resolved === null) {
            return null;
        }

        // Attempt to resolve a generic type argument from the PHPDoc @return tag.
        $innerClass = $this->resolvePhpDocReturnGeneric($method, $className);

        if ($innerClass === null) {
            return $resolved;
        }

        $innerResolved = $this->lookupTransformerType($innerClass);

        if ($innerResolved === null) {
            return $resolved;
        }

        return [
            'type' => "{$resolved['type']}<{$innerResolved['type']}>",
            'file' => $resolved['file'],
            'ambient' => $resolved['ambient'],
        ];
    }

    /**
     * Parse the PHPDoc @return tag on the method and return the first generic
     * type argument's fully-qualified class name when the outer type matches
     * the reflected return class.
     *
     * e.g. "@return ApiResponseData<StoreUserResponseData>" → resolves the
     * short name "StoreUserResponseData" to its FQN using the class's use statements.
     */
    private function resolvePhpDocReturnGeneric(ReflectionMethod $method, string $outerClass): ?string
    {
        try {
            $docComment = $method->getDocComment();

            if ($docComment === false) {
                return null;
            }

            $config = new ParserConfig([]);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
            $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
            $lexer = new Lexer($config);

            $tokens = new TokenIterator($lexer->tokenize($docComment));
            $phpDoc = $phpDocParser->parse($tokens);

            foreach ($phpDoc->getReturnTagValues() as $returnTag) {
                $type = $returnTag->type;

                if (!$type instanceof GenericTypeNode) {
                    continue;
                }

                if (count($type->genericTypes) !== 1) {
                    continue;
                }

                $innerTypeNode = $type->genericTypes[0];

                if (!$innerTypeNode instanceof IdentifierTypeNode) {
                    continue;
                }

                return $this->resolveClassName($innerTypeNode->name, $method->getDeclaringClass());
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Resolve a short class name from a PHPDoc tag to its fully-qualified name
     * by inspecting the declaring class's namespace and use statements.
     */
    private function resolveClassName(string $shortName, \ReflectionClass $declaringClass): ?string
    {
        // Already fully qualified.
        if (str_starts_with($shortName, '\\')) {
            return ltrim($shortName, '\\');
        }

        $source = @file_get_contents($declaringClass->getFileName());

        if ($source === false) {
            return null;
        }

        // Extract use statements: "use Foo\Bar\Baz;" or "use Foo\Bar\Baz as Alias;"
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fqn = $match[1];
            $alias = $match[2] ?? class_basename($fqn);

            if ($alias === $shortName) {
                return $fqn;
            }
        }

        // Fall back to same namespace as the declaring class.
        $namespace = $declaringClass->getNamespaceName();

        if ($namespace !== '') {
            $candidate = $namespace . '\\' . $shortName;

            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return class_exists($shortName) ? $shortName : null;
    }

    private function isDataClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        foreach (['Spatie\\LaravelData\\Data', 'Spatie\\LaravelData\\Resource'] as $base) {
            if (class_exists($base) && (is_a($className, $base, true))) {
                return true;
            }
        }

        return false;
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
     * The output file path is read from the `TypeScriptTransformerConfig` singleton
     * bound by spatie/laravel-typescript-transformer v3 via `GlobalNamespaceWriter::$path`.
     *
     * @return array{type: string, file: string, ambient: bool}|null
     */
    private function lookupTransformerType(string $dataClass): ?array
    {
        try {
            $outputFile = $this->resolveOutputFileFromContainer();

            if (!is_string($outputFile) || !file_exists($outputFile)) {
                return null;
            }

            $content = @file_get_contents($outputFile);

            if ($content === false) {
                return null;
            }

            $resolvedFile = realpath($outputFile) ?: $outputFile;
            $baseName = class_basename($dataClass);

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

    /**
     * Attempt to derive the output file path from the TypeScriptTransformerConfig
     * singleton bound by spatie/laravel-typescript-transformer v3.
     *
     * The config exposes `$typesWriter`; if it is a GlobalNamespaceWriter we can
     * read its protected `$path` property via reflection to get the exact file the
     * transformer will write to.  For any other Writer implementation we fall back
     * to `$outputDirectory/index.d.ts`.
     *
     * Returns null if the package is not installed or the binding is absent.
     */
    private function resolveOutputFileFromContainer(): ?string
    {
        if (!class_exists('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig')) {
            return null;
        }

        try {
            $config = app('Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig');
        } catch (Throwable) {
            return null;
        }

        $outputDirectory = isset($config->outputDirectory) && is_string($config->outputDirectory)
            ? rtrim($config->outputDirectory, '/\\')
            : null;

        // Prefer reading the exact filename off the writer via reflection,
        // then combine it with the output directory.
        if (isset($config->typesWriter) && $config->typesWriter instanceof GlobalNamespaceWriter) {
            try {
                $prop = new \ReflectionProperty($config->typesWriter, 'path');
                $prop->setAccessible(true);
                $writerPath = $prop->getValue($config->typesWriter);

                if (is_string($writerPath) && $writerPath !== '') {
                    // The writer stores only the filename (e.g. "index.d.ts").
                    // Combine with outputDirectory when the path is not already absolute.
                    if (!str_starts_with($writerPath, '/') && $outputDirectory !== null) {
                        return $outputDirectory . DIRECTORY_SEPARATOR . $writerPath;
                    }

                    return $writerPath;
                }
            } catch (Throwable) {
            }
        }

        // Fall back to the output directory + conventional filename.
        if ($outputDirectory === null) {
            return null;
        }

        return $outputDirectory . DIRECTORY_SEPARATOR . 'index.d.ts';
    }
}
