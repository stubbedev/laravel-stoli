<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use StubbeDev\LaravelStoli\Compilers\TypeScript;
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
     * Return types that are sent as a plain JSON array of their items.
     */
    private const LIST_TYPES = [
        'array',
        'iterable',
        'Illuminate\\Support\\Enumerable',
        self::DATA_COLLECTION,
    ];

    private const DATA_COLLECTION = 'Spatie\\LaravelData\\DataCollection';

    /**
     * Return types laravel-data sends wrapped in data/links/meta, by the stoli.d.ts
     * type describing that envelope.
     */
    private const PAGINATED_TYPES = [
        'Spatie\\LaravelData\\PaginatedDataCollection' => 'Paginated',
        'Spatie\\LaravelData\\CursorPaginatedDataCollection' => 'CursorPaginated',
    ];

    /**
     * Exported type path => whether it is ambient, read once from the types file.
     *
     * @var array<string, bool>|null
     */
    private ?array $exports = null;

    private readonly ClassNameResolver $classNames;

    public function __construct(
        private readonly TransformerOutput $output,
        private readonly ?Repository $config = null,
    ) {
        $this->classNames = new ClassNameResolver;
    }

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
     * The type the method responds with, from its return type and PHPDoc @return tag:
     *
     *  - a Data class, with a generic argument when the tag gives it one:
     *    `@return ApiResponseData<UserData>` becomes ApiResponseData<UserData>;
     *  - an array, Collection or DataCollection of Data, as an array of it:
     *    `@return DataCollection<int, UserData>` becomes UserData[];
     *  - a paginated DataCollection of Data, in the envelope laravel-data sends:
     *    `@return PaginatedDataCollection<int, UserData>` becomes Paginated<UserData>.
     *
     * laravel-data wraps what a controller returns the way the response does: a Data
     * object under its defaultWrap() key or the `data.wrap` config key, a DataCollection
     * under the config key, and a paginated one renames its `data` key to it. A plain
     * array or Collection is serialized by Laravel and never wrapped.
     */
    private function resolveResponseType(ReflectionMethod $method): ?DataType
    {
        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType) {
            return null;
        }

        $class = $returnType->getName();
        $tag = $this->returnTag($method);

        if (self::isDataClass($class)) {
            $resolved = $this->lookup($class);
            $argument = $tag instanceof GenericTypeNode && count($tag->genericTypes) === 1
                ? $this->argumentType($tag->genericTypes[0], $method)
                : null;

            $resolved = $argument === null ? $resolved : $resolved?->withArgument($argument);
            if (self::sendsOwnResponse($class)) {
                return $resolved;
            }

            $wrap = self::classWrap($class) ?? $this->globalWrap();

            return $wrap === null ? $resolved : $resolved?->wrapped($wrap);
        }

        $item = $this->itemType($tag, $method);

        if ($item === null) {
            return null;
        }

        $envelope = self::PAGINATED_TYPES[$class] ?? null;

        if ($envelope !== null) {
            // The envelope types ship in stoli.d.ts, next to the route service.
            $directory = $this->output->directory;

            if ($directory === null) {
                return null;
            }

            $envelopeType = DataType::imported($envelope, Utils::absolutePath($directory.'/stoli.d.ts'));
            $wrap = $this->globalWrap();

            return $wrap === null || $wrap === 'data'
                ? $envelopeType->withArgument($item)
                : $envelopeType->withArgument($item, TypeScript::string($wrap));
        }

        foreach (self::LIST_TYPES as $listType) {
            if ($class === $listType || is_a($class, $listType, true)) {
                $wrap = is_a($class, self::DATA_COLLECTION, true) ? $this->globalWrap() : null;

                return $wrap === null ? $item->list() : $item->list()->wrapped($wrap);
            }
        }

        return null;
    }

    /**
     * A Data class that overrides toResponse() decides its own JSON shape, so
     * laravel-data never gets the chance to wrap it.
     */
    private static function sendsOwnResponse(string $class): bool
    {
        if (! method_exists($class, 'toResponse')) {
            return false;
        }

        $declaring = (new ReflectionMethod($class, 'toResponse'))->getDeclaringClass()->getName();

        return ! str_starts_with($declaring, 'Spatie\\LaravelData\\');
    }

    /**
     * The key a Data class wraps itself in through defaultWrap(), which takes
     * precedence over the `data.wrap` config key.
     */
    private static function classWrap(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            // defaultWrap() is an instance method, but only ever returns a key.
            $data = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $key = method_exists($data, 'defaultWrap') ? $data->defaultWrap() : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The `data.wrap` key of the laravel-data config: null when responses are not wrapped.
     */
    private function globalWrap(): ?string
    {
        $key = $this->config?->get('data.wrap');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * A generic argument: a transformed class, or a non-class type from self::TS_LITERAL_TYPES
     * (the "null" in "@return ApiResponseData<null>").
     */
    private function argumentType(TypeNode $node, ReflectionMethod $method): DataType|string|null
    {
        if (! $node instanceof IdentifierTypeNode) {
            return null;
        }

        return self::TS_LITERAL_TYPES[strtolower($node->name)] ?? $this->classType($node->name, $method);
    }

    /**
     * The Data class a collection holds: the last generic argument of
     * `Collection<int, UserData>`, or the item of `UserData[]`.
     */
    private function itemType(?TypeNode $tag, ReflectionMethod $method): ?DataType
    {
        $item = match (true) {
            $tag instanceof GenericTypeNode => array_values($tag->genericTypes)[count($tag->genericTypes) - 1] ?? null,
            $tag instanceof ArrayTypeNode => $tag->type,
            default => null,
        };

        return $item instanceof IdentifierTypeNode ? $this->classType($item->name, $method) : null;
    }

    /**
     * The type the transformer generated for a class named in a PHPDoc tag. Any class
     * it transformed will do, so an enum argument (ApiResponseData<Status>) works too.
     */
    private function classType(string $name, ReflectionMethod $method): ?DataType
    {
        $class = $this->classNames->resolve($name, $method->getDeclaringClass());

        return $class === null ? null : $this->lookup($class);
    }

    /**
     * The type of the method's PHPDoc @return tag.
     */
    private function returnTag(ReflectionMethod $method): ?TypeNode
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
            return $returnTag->type;
        }

        return null;
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
            return new DataType($dotted);
        }

        $baseName = class_basename($class);

        if (! isset($exports[$baseName])) {
            return null;
        }

        return $exports[$baseName]
            ? new DataType($baseName)
            : DataType::imported($baseName, $file);
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
