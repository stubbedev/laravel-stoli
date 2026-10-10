<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use BackedEnum;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use StubbeDev\LaravelStoli\Compilers\TypeScript;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\Requests\RequestTypes;
use Throwable;

/**
 * Resolves the TypeScript types a controller action takes and responds with, from its
 * signature and PHPDoc @return tag, using the types the typescript-transformer
 * generated for Spatie Data classes (and any other class it transformed).
 *
 * PHPDoc types are converted recursively: `ApiResponseData<list<UserData>|null>`,
 * `array<string, UserData>` and `array{user: UserData, total?: int}` all come out as
 * their TypeScript counterparts. A type with any part that cannot be converted is not
 * guessed at: the whole type is left unresolved.
 *
 * How a transformed class is referenced is up to TransformedTypes.
 */
final class SpatieDataTypeResolver
{
    /**
     * PHPDoc types that are not classes.
     */
    private const SCALARS = [
        'null' => 'null',
        'void' => 'void',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'true' => 'true',
        'false' => 'false',
        'int' => 'number',
        'integer' => 'number',
        'positive-int' => 'number',
        'negative-int' => 'number',
        'non-negative-int' => 'number',
        'non-positive-int' => 'number',
        'non-zero-int' => 'number',
        'float' => 'number',
        'double' => 'number',
        'numeric' => 'number',
        'string' => 'string',
        'non-empty-string' => 'string',
        'non-falsy-string' => 'string',
        'truthy-string' => 'string',
        'numeric-string' => '`${number}`',
        'lowercase-string' => 'string',
        'class-string' => 'string',
        'array-key' => 'string | number',
        'scalar' => 'string | number | boolean',
        'mixed' => 'unknown',
        'object' => 'Record<string, unknown>',
    ];

    /**
     * Array types that hold items, with whether they are always lists.
     */
    private const ARRAYS = [
        'array' => false,
        'non-empty-array' => false,
        'iterable' => false,
        'list' => true,
        'non-empty-list' => true,
    ];

    /**
     * Keys a collection is sent as a JSON array with.
     */
    private const LIST_KEYS = ['int', 'integer', 'positive-int', 'non-negative-int'];

    private const DATA_CLASSES = [
        'Spatie\\LaravelData\\Data',
        'Spatie\\LaravelData\\Resource',
    ];

    private const DATA_COLLECTION = 'Spatie\\LaravelData\\DataCollection';

    /**
     * Collections laravel-data sends wrapped in data/links/meta, by the route service
     * type describing that envelope.
     */
    private const PAGINATED = [
        'Spatie\\LaravelData\\PaginatedDataCollection' => 'Paginated',
        'Spatie\\LaravelData\\CursorPaginatedDataCollection' => 'CursorPaginated',
    ];

    private readonly ClassNameResolver $classNames;

    public function __construct(
        private readonly TransformedTypes $transformed,
        private readonly RequestTypes $requests,
        private readonly ?Repository $config = null,
    ) {
        $this->classNames = new ClassNameResolver;
    }

    /**
     * What the action takes in: its first Data or FormRequest parameter, as a request
     * takes it.
     */
    public function request(LaravelRoute $route): ?DataType
    {
        foreach (self::action($route)?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $class = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

            if ($class !== null && self::isData($class)) {
                return $this->requests->data($class);
            }

            if ($class !== null && RequestTypes::isFormRequest($class)) {
                return $this->requests->formRequest($class);
            }
        }

        return null;
    }

    /**
     * The types the action's signature gives its route parameters: a backed enum takes
     * its values, an int or float a number, a string a string, and a model the type of
     * the key it is bound by. Laravel binds a route parameter to the action parameter
     * of the same name.
     *
     * @return array<string, string> route parameter => TypeScript type
     */
    public function parameters(LaravelRoute $route): array
    {
        $types = [];

        foreach (self::action($route)?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            $name = $type->getName();
            $mapped = match (true) {
                $name === 'int', $name === 'float' => 'number',
                $name === 'string' => 'string',
                is_subclass_of($name, BackedEnum::class) => self::enumValues($name),
                is_subclass_of($name, Model::class) => self::modelKey($name, $route->bindingFieldFor($parameter->getName())),
                default => null,
            };

            if ($mapped !== null) {
                $types[$parameter->getName()] = $mapped;
            }
        }

        return $types;
    }

    /**
     * The type of the key a model is bound by: its integer primary key is a number, any
     * other key, such as a slug, text.
     *
     * @param  class-string<Model>  $model
     */
    private static function modelKey(string $model, ?string $field): ?string
    {
        try {
            $instance = (new ReflectionClass($model))->newInstanceWithoutConstructor();
            $key = $field ?? $instance->getRouteKeyName();
        } catch (Throwable) {
            return null;
        }

        return $key === $instance->getKeyName() && in_array($instance->getKeyType(), ['int', 'integer'], true) ? 'number' : 'string';
    }

    /**
     * The values of a backed enum, an integer one also as the text a URL carries it as.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    private static function enumValues(string $enum): ?string
    {
        $values = [];

        foreach ($enum::cases() as $case) {
            $values[] = TypeScript::string((string) $case->value);

            if (is_int($case->value)) {
                $values[] = (string) $case->value;
            }
        }

        return $values === [] ? null : TypeScript::union($values);
    }

    /**
     * What the action responds with: its @return tag, or its return type without one.
     *
     * laravel-data wraps what a controller returns the way the response does: a Data
     * object under its defaultWrap() key or the `data.wrap` config key, a DataCollection
     * under the config key, and a paginated one renames its `data` key to it. A plain
     * array or Collection is serialized by Laravel and never wrapped.
     */
    public function response(LaravelRoute $route): ?DataType
    {
        $returned = $this->returned($route);

        if ($returned === null) {
            return null;
        }

        [$type, $class] = $returned;

        $wrap = match (true) {
            $class === null => null,
            self::isData($class) => self::sendsOwnResponse($class) ? null : self::classWrap($class) ?? $this->globalWrap(),
            is_a($class, self::DATA_COLLECTION, true) => $this->globalWrap(),
            default => null,
        };

        return $wrap === null ? $type : $type->wrapped($wrap);
    }

    /**
     * The status the action responds with when it succeeds: laravel-data sends a Data
     * object or collection with 201 to a POST and 200 otherwise, and Laravel sends
     * anything else it serializes itself with 200. A response the action builds itself
     * can have any status.
     *
     * @param  list<string>  $methods  the HTTP methods the route answers to, lowercase
     */
    public function status(LaravelRoute $route, array $methods): string
    {
        $returned = $this->returned($route);

        if ($returned === null) {
            return 'number';
        }

        [, $class] = $returned;

        if ($class === null || (! self::isData($class) && ! is_a($class, self::DATA_COLLECTION, true) && ! isset(self::PAGINATED[$class]))) {
            return $class === null || is_a($class, Enumerable::class, true) ? '200' : 'number';
        }

        if (self::isData($class) && self::sendsOwnResponse($class)) {
            return 'number';
        }

        $statuses = array_unique(array_map(static fn (string $method): string => $method === 'post' ? '201' : '200', $methods));
        sort($statuses);

        return $statuses === [] ? '200' : implode(' | ', $statuses);
    }

    /**
     * The type the action returns, and the class that is returned, which decides how it
     * is sent whatever the @return tag says it holds; null for an array or scalar.
     *
     * @return array{DataType, string|null}|null
     */
    private function returned(LaravelRoute $route): ?array
    {
        $method = self::action($route);
        $native = $method?->getReturnType();
        $node = $method === null ? null : (self::returnTag($method) ?? self::node($native));

        if ($method === null || $node === null) {
            return null;
        }

        $context = $method->getDeclaringClass();
        $type = $this->type($node, $context);

        if ($type === null) {
            return null;
        }

        $class = $native instanceof ReflectionNamedType && ! $native->isBuiltin()
            ? $native->getName()
            : $this->outerClass($node, $context);

        return [$type, $class];
    }

    private static function action(LaravelRoute $route): ?ReflectionMethod
    {
        $action = $route->getAction('uses');

        if (! is_string($action)) {
            return null;
        }

        $controller = Str::before($action, '@');

        if (! class_exists($controller)) {
            return null;
        }

        try {
            return new ReflectionMethod($controller, str_contains($action, '@') ? Str::after($action, '@') : '__invoke');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A native type as the PHPDoc type it is the same as.
     */
    private static function node(?ReflectionType $type): ?TypeNode
    {
        if ($type instanceof ReflectionUnionType) {
            $members = array_map(self::node(...), $type->getTypes());

            return in_array(null, $members, true) ? null : new UnionTypeNode(array_values($members));
        }

        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        $node = new IdentifierTypeNode($type->isBuiltin() ? $type->getName() : '\\'.$type->getName());

        return $type->allowsNull() && ! in_array($type->getName(), ['null', 'mixed'], true)
            ? new NullableTypeNode($node)
            : $node;
    }

    /**
     * The TypeScript type of a PHPDoc type written in $context.
     *
     * @param  ReflectionClass<object>  $context
     */
    private function type(TypeNode $node, ReflectionClass $context): ?DataType
    {
        return match (true) {
            $node instanceof IdentifierTypeNode => $this->named($node->name, [], $context),
            $node instanceof GenericTypeNode => $this->named($node->type->name, array_values($node->genericTypes), $context),
            $node instanceof NullableTypeNode => $this->union([$node->type, new IdentifierTypeNode('null')], $context),
            $node instanceof UnionTypeNode => $this->union(array_values($node->types), $context),
            $node instanceof ArrayTypeNode => $this->type($node->type, $context)?->list(),
            $node instanceof ArrayShapeNode => $this->shape($node, $context),
            $node instanceof ConstTypeNode => self::constant($node),
            default => null,
        };
    }

    /**
     * @param  list<TypeNode>  $nodes
     * @param  ReflectionClass<object>  $context
     */
    private function union(array $nodes, ReflectionClass $context): ?DataType
    {
        $types = $this->types($nodes, $context);

        return $types === null || $types === [] ? null : DataType::union(...$types);
    }

    /**
     * Every one of the types, or null when any of them cannot be converted.
     *
     * @param  list<TypeNode>  $nodes
     * @param  ReflectionClass<object>  $context
     * @return list<DataType>|null
     */
    private function types(array $nodes, ReflectionClass $context): ?array
    {
        $types = [];

        foreach ($nodes as $node) {
            $type = $this->type($node, $context);

            if ($type === null) {
                return null;
            }

            $types[] = $type;
        }

        return $types;
    }

    /**
     * @param  list<TypeNode>  $arguments
     * @param  ReflectionClass<object>  $context
     */
    private function named(string $name, array $arguments, ReflectionClass $context): ?DataType
    {
        $lower = strtolower($name);

        if (isset(self::SCALARS[$lower])) {
            return $arguments === [] ? new DataType(self::SCALARS[$lower]) : null;
        }

        if (isset(self::ARRAYS[$lower])) {
            return $this->collection($arguments, self::ARRAYS[$lower], $context);
        }

        $class = $this->resolveClass($name, $context);

        if ($class === null) {
            return null;
        }

        if (isset(self::PAGINATED[$class])) {
            $item = $arguments === [] ? null : $this->type($arguments[array_key_last($arguments)], $context);
            $envelope = DataType::runtime(self::PAGINATED[$class]);
            $wrap = $this->globalWrap();

            return $item === null ? null : $envelope->withArguments(
                $item,
                ...($wrap === null || $wrap === 'data' ? [] : [new DataType(TypeScript::string($wrap))]),
            );
        }

        if (is_a($class, Enumerable::class, true) || is_a($class, self::DATA_COLLECTION, true)) {
            return $this->collection($arguments, false, $context);
        }

        $arguments = $this->types($arguments, $context);

        return $arguments === null ? null : $this->transformed->find($class, $arguments);
    }

    /**
     * A collection as it is sent: a JSON array, or an object when it is keyed by strings.
     * Without an item type there is nothing to say about it.
     *
     * @param  list<TypeNode>  $arguments  the value type, or the key and value types
     * @param  ReflectionClass<object>  $context
     */
    private function collection(array $arguments, bool $list, ReflectionClass $context): ?DataType
    {
        $value = $arguments === [] ? null : $this->type($arguments[array_key_last($arguments)], $context);

        if ($value === null) {
            return null;
        }

        $key = count($arguments) > 1 ? $arguments[0] : null;

        return $list || $key === null || ($key instanceof IdentifierTypeNode && in_array(strtolower($key->name), self::LIST_KEYS, true))
            ? $value->list()
            : $value->record();
    }

    /**
     * @param  ReflectionClass<object>  $context
     */
    private function shape(ArrayShapeNode $node, ReflectionClass $context): ?DataType
    {
        $properties = [];
        $optional = [];

        foreach ($node->items as $item) {
            $key = match (true) {
                $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                $item->keyName instanceof ConstExprIntegerNode => $item->keyName->value,
                default => null,
            };
            $type = $this->type($item->valueType, $context);

            // An unnamed item makes it a list shape, which is not converted.
            if ($key === null || $type === null) {
                return null;
            }

            $properties[$key] = $type;

            if ($item->optional) {
                $optional[] = $key;
            }
        }

        return DataType::object($properties, $optional);
    }

    private static function constant(ConstTypeNode $node): ?DataType
    {
        $value = $node->constExpr;

        return match (true) {
            $value instanceof ConstExprStringNode => new DataType(TypeScript::string($value->value)),
            $value instanceof ConstExprIntegerNode, $value instanceof ConstExprFloatNode => new DataType(str_replace('_', '', $value->value)),
            default => null,
        };
    }

    /**
     * The class the outermost part of a PHPDoc type names.
     *
     * @param  ReflectionClass<object>  $context
     */
    private function outerClass(TypeNode $node, ReflectionClass $context): ?string
    {
        $name = match (true) {
            $node instanceof IdentifierTypeNode => $node->name,
            $node instanceof GenericTypeNode => $node->type->name,
            default => null,
        };

        return $name === null ? null : $this->resolveClass($name, $context);
    }

    /**
     * @param  ReflectionClass<object>  $context
     * @return class-string|null
     */
    private function resolveClass(string $name, ReflectionClass $context): ?string
    {
        return in_array(strtolower($name), ['self', 'static', '$this'], true)
            ? $context->getName()
            : $this->classNames->resolve($name, $context);
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
     * The type of the method's PHPDoc @return tag.
     */
    private static function returnTag(ReflectionMethod $method): ?TypeNode
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

        return ($phpDoc->getReturnTagValues()[0] ?? null)?->type;
    }

    private static function isData(string $class): bool
    {
        foreach (self::DATA_CLASSES as $base) {
            if (is_a($class, $base, true)) {
                return true;
            }
        }

        return false;
    }
}
