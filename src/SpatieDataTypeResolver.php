<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use StubbeDev\LaravelStoli\Items\DataType;
use Throwable;

use function class_basename;

/**
 * Resolves request and response TypeScript type references for controller actions
 * that use Spatie Laravel Data objects.
 *
 * When a controller method accepts a Data subclass as its request parameter, this
 * resolver looks up the corresponding TypeScript type in the typescript-transformer
 * types file. The same lookup is performed on the return type for the response,
 * including a generic argument from the PHPDoc @return tag.
 *
 * Types found inside a `declare namespace` (the GlobalNamespaceWriter output) are
 * ambient globals, referenced by their dotted path (e.g.
 * `App.Http.Data.StoreUserRequestData`) with nothing to import. Types exported at
 * the top level of a module file are referenced by their bare name and imported.
 *
 * Routes that do not use Spatie Data objects return null for both.
 */
final class SpatieDataTypeResolver
{
    /**
     * Inner generic arguments that are not classes (e.g. ApiResponseData<null>)
     * are rendered directly as TypeScript instead of being dropped.
     */
    private const TS_LITERAL_TYPES = [
        'null' => 'null',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'true' => 'true',
        'false' => 'false',
        'int' => 'number',
        'integer' => 'number',
        'float' => 'number',
        'string' => 'string',
        'mixed' => 'unknown',
    ];

    private const DATA_CLASSES = [
        'Spatie\\LaravelData\\Data',
        'Spatie\\LaravelData\\Resource',
    ];

    /**
     * Exported type path => whether it is ambient, read once from the types file.
     *
     * @var array<string, bool>|null
     */
    private ?array $exports = null;

    public function __construct(private readonly TransformerOutput $output) {}

    /**
     * @return array{request: ?DataType, response: ?DataType}
     */
    public function resolve(LaravelRoute $route): array
    {
        $method = self::actionMethod($route);

        if ($method === null) {
            return ['request' => null, 'response' => null];
        }

        return [
            'request' => $this->resolveRequestType($method),
            'response' => $this->resolveResponseType($method),
        ];
    }

    private static function actionMethod(LaravelRoute $route): ?ReflectionMethod
    {
        $action = $route->getAction('uses');

        if (! is_string($action)) {
            return null;
        }

        $controller = Str::before($action, '@');
        $method = str_contains($action, '@') ? Str::after($action, '@') : '__invoke';

        if (! class_exists($controller)) {
            return null;
        }

        try {
            return new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The first Spatie Data parameter of the method.
     */
    private function resolveRequestType(ReflectionMethod $method): ?DataType
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && self::isDataClass($type->getName())) {
                return $this->lookup($type->getName());
            }
        }

        return null;
    }

    /**
     * The Data class the method returns.
     *
     * When the PHPDoc @return tag gives it a generic argument, the argument is
     * resolved too: ApiResponseData<StoreUserResponseData> becomes
     * App.Http.Data.ApiResponseData<App.Http.Data.StoreUserResponseData>.
     */
    private function resolveResponseType(ReflectionMethod $method): ?DataType
    {
        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || ! self::isDataClass($returnType->getName())) {
            return null;
        }

        $resolved = $this->lookup($returnType->getName());
        $argument = $resolved === null ? null : self::phpDocGenericArgument($method);

        if ($resolved === null || $argument === null) {
            return $resolved;
        }

        $literal = self::TS_LITERAL_TYPES[strtolower($argument)] ?? null;

        if ($literal !== null) {
            return $resolved->withArgument($literal);
        }

        $inner = $this->lookup($argument);

        return $inner === null ? $resolved : $resolved->withArgument($inner->type, $inner->imports);
    }

    /**
     * The single generic argument of the PHPDoc @return tag, as a fully-qualified
     * class name, or verbatim when it is one of self::TS_LITERAL_TYPES (e.g. the
     * "null" in "@return ApiResponseData<null>").
     */
    private static function phpDocGenericArgument(ReflectionMethod $method): ?string
    {
        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return null;
        }

        try {
            $config = new ParserConfig([]);
            $constExprParser = new ConstExprParser($config);
            $parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
            $phpDoc = $parser->parse(new TokenIterator((new Lexer($config))->tokenize($docComment)));
        } catch (Throwable) {
            return null;
        }

        foreach ($phpDoc->getReturnTagValues() as $returnTag) {
            $type = $returnTag->type;

            if (! $type instanceof GenericTypeNode || count($type->genericTypes) !== 1) {
                continue;
            }

            $argument = $type->genericTypes[0];

            if (! $argument instanceof IdentifierTypeNode) {
                continue;
            }

            if (isset(self::TS_LITERAL_TYPES[strtolower($argument->name)])) {
                return $argument->name;
            }

            return self::resolveClassName($argument->name, $method->getDeclaringClass());
        }

        return null;
    }

    /**
     * Resolve a short class name from a PHPDoc tag to its fully-qualified name
     * by inspecting the declaring class's namespace and use statements.
     *
     * @param  ReflectionClass<object>  $declaringClass
     */
    private static function resolveClassName(string $shortName, ReflectionClass $declaringClass): ?string
    {
        if (str_starts_with($shortName, '\\')) {
            return ltrim($shortName, '\\');
        }

        $file = $declaringClass->getFileName();
        $source = $file === false ? false : @file_get_contents($file);

        if ($source === false) {
            return null;
        }

        // "use Foo\Bar\Baz;" or "use Foo\Bar\Baz as Alias;"
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (($match[2] ?? class_basename($match[1])) === $shortName) {
                return $match[1];
            }
        }

        $namespace = $declaringClass->getNamespaceName();
        $candidate = $namespace === '' ? $shortName : "{$namespace}\\{$shortName}";

        if (class_exists($candidate)) {
            return $candidate;
        }

        return class_exists($shortName) ? $shortName : null;
    }

    private static function isDataClass(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        foreach (self::DATA_CLASSES as $base) {
            if (is_a($className, $base, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the type the transformer generated for a PHP class: at its namespace path
     * when it is ambient, or by its bare name when it is a module export.
     */
    private function lookup(string $class): ?DataType
    {
        $file = $this->output->typesFile;
        $exports = $this->exports();

        if ($file === null || $exports === []) {
            return null;
        }

        $file = realpath($file) ?: $file;
        $dotted = str_replace('\\', '.', ltrim($class, '\\'));

        if ($exports[$dotted] ?? false) {
            return new DataType($dotted, $file);
        }

        $baseName = class_basename($class);

        if (! isset($exports[$baseName])) {
            return null;
        }

        return $exports[$baseName]
            ? new DataType($baseName, $file)
            : new DataType($baseName, $file, [$baseName]);
    }

    /**
     * @return array<string, bool>
     */
    private function exports(): array
    {
        if ($this->exports !== null) {
            return $this->exports;
        }

        $file = $this->output->typesFile;
        $source = $file !== null && is_file($file) ? @file_get_contents($file) : false;

        return $this->exports = $source === false ? [] : self::parseExports($source);
    }

    /**
     * Every exported type and interface in a types file, keyed by its dotted path
     * through the enclosing namespaces. Braces are tracked to know which namespace
     * a declaration sits in; a type inside `declare namespace` or `declare global`
     * is ambient.
     *
     * @return array<string, bool>
     */
    private static function parseExports(string $source): array
    {
        preg_match_all(
            '/(?<declare>\bdeclare\s+)?(?:\bnamespace\s+(?<namespace>[\w$.]+)\s*\{|\bglobal\s*\{)'
            .'|\bexport\s+(?:declare\s+)?(?:type|interface)\s+(?<type>[A-Za-z_$][\w$]*)'
            .'|(?<brace>[{}])/',
            $source,
            $tokens,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        $stack = [];
        $exports = [];

        foreach ($tokens as $token) {
            $ambient = $stack !== [] && $stack[array_key_last($stack)]['ambient'];

            if ($token['type'] !== null) {
                $path = [...array_merge(...array_column($stack, 'names')), $token['type']];
                $exports[implode('.', $path)] ??= $ambient;
            } elseif ($token['brace'] === '}') {
                array_pop($stack);
            } elseif ($token['brace'] === '{') {
                $stack[] = ['names' => [], 'ambient' => $ambient];
            } else {
                $stack[] = [
                    'names' => $token['namespace'] === null ? [] : explode('.', $token['namespace']),
                    'ambient' => $ambient || $token['declare'] !== null || $token['namespace'] === null,
                ];
            }
        }

        return $exports;
    }
}
