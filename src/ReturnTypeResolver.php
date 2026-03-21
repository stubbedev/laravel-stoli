<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Throwable;

final readonly class ReturnTypeResolver
{
    public function __construct(private Container $container)
    {
    }

    /**
     * @return array{wrap: string|null, collection: bool, shape: array<string, string>}|null
     */
    public function resolve(LaravelRoute $route): ?array
    {
        $action = $route->getAction('uses');

        if (!is_string($action)) {
            return null;
        }

        if (str_contains($action, '@')) {
            [$controller, $method] = explode('@', $action, 2);
        } else {
            $controller = $action;
            $method     = '__invoke';
        }

        if (!class_exists($controller)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return null;
        }

        return $this->tryActualExecution($route)
            ?? $this->resolveFromReturnType($reflection)
            ?? $this->resolveFromMethodBody($reflection);
    }

    // -------------------------------------------------------------------------
    // Layer 0: actual execution via container with seeded models + DB rollback
    // -------------------------------------------------------------------------

    private function tryActualExecution(LaravelRoute $route): ?array
    {
        // Only safe to call GET routes — no side effects
        if (!in_array('GET', $route->methods() ?? [])) {
            return null;
        }

        $action = $route->getAction('uses');
        if (!is_string($action)) {
            return null;
        }

        $response = null;

        try {
            DB::beginTransaction();

            try {
                // Seed and bind each route parameter model, build the URI
                $uri = $this->buildUriWithSeededModels($route);

                $fakeRequest = Request::create('/' . $uri, 'GET');
                $fakeRequest->setRouteResolver(fn() => $route);

                $prevRequest = $this->container->make('request');
                $this->container->instance('request', $fakeRequest);
                $this->container->instance(Request::class, $fakeRequest);

                $prevUser = Auth::user();
                $this->loginWithSeededUser();

                try {
                    $response = $this->container->call($action);
                } finally {
                    $this->container->instance('request', $prevRequest);
                    $this->container->instance(Request::class, $prevRequest);
                    Auth::setUser($prevUser);
                }
            } finally {
                DB::rollBack();
            }
        } catch (Throwable) {
            return null;
        }

        $jsonResponse = self::toJsonResponse($response);
        if ($jsonResponse === null) {
            return null;
        }

        $decoded = json_decode($jsonResponse->getContent(), true);
        if (!is_array($decoded) || empty($decoded)) {
            return null;
        }

        $shape = [];
        foreach ($decoded as $key => $value) {
            $shape[(string) $key] = self::jsonValueToTypeString($value);
        }

        return ['wrap' => null, 'collection' => false, 'shape' => $shape];
    }

    private static function toJsonResponse(mixed $response): ?JsonResponse
    {
        if ($response instanceof JsonResponse) {
            return $response;
        }

        if ($response instanceof JsonResource) {
            $r = $response->toResponse(new Request());
            return $r instanceof JsonResponse ? $r : null;
        }

        if (class_exists('Spatie\\LaravelData\\Data') && $response instanceof \Spatie\LaravelData\Data) {
            $r = $response->toResponse(new Request());
            return $r instanceof JsonResponse ? $r : null;
        }

        return null;
    }

    /**
     * Seed model instances for every route URI parameter, bind them to the
     * route object, and return the resolved URI with their primary keys filled in.
     */
    private function buildUriWithSeededModels(LaravelRoute $route): string
    {
        $uri        = $route->uri();
        $paramTypes = $this->resolveParamModelClasses($route);

        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $param    = $match[1];
            $optional = !empty($match[2]);
            $full     = $match[0]; // e.g. {user} or {user?}

            $key = 1;

            if (isset($paramTypes[$param])) {
                $instance = $this->seedModel($paramTypes[$param]);
                if ($instance !== null) {
                    $route->setParameter($param, $instance);
                    $key = $instance->getKey() ?? 1;
                }
            }

            $uri = str_replace($full, $optional ? '' : (string) $key, $uri);
        }

        return trim(preg_replace('#//+#', '/', $uri), '/');
    }

    /**
     * Map each URI parameter name to its Eloquent model class by inspecting
     * the controller method's type-hinted parameters.
     *
     * @return array<string, class-string<Model>>
     */
    private function resolveParamModelClasses(LaravelRoute $route): array
    {
        $action = $route->getAction('uses');
        if (!is_string($action)) {
            return [];
        }

        [$controller, $method] = str_contains($action, '@')
            ? explode('@', $action, 2)
            : [$action, '__invoke'];

        try {
            $map = [];

            foreach ((new ReflectionMethod($controller, $method))->getParameters() as $param) {
                $type = $param->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $class = $type->getName();

                if (class_exists($class) && is_subclass_of($class, Model::class)) {
                    $map[$param->getName()] = $class;
                }
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Create and persist a model instance using its factory if available,
     * falling back to generating attributes from the database schema.
     */
    private function seedModel(string $modelClass): ?Model
    {
        // Factory path
        if (method_exists($modelClass, 'factory')) {
            try {
                return $modelClass::factory()->create();
            } catch (Throwable) {
            }
        }

        // Schema-derived path
        try {
            /** @var Model $instance */
            $instance   = new $modelClass();
            $table      = $instance->getTable();
            $attributes = [];

            foreach (Schema::getColumns($table) as $column) {
                $name = $column['name'];

                if ($name === $instance->getKeyName() && ($column['auto_increment'] ?? false)) {
                    continue;
                }

                if ($column['nullable'] ?? false) {
                    continue; // let nullable columns default to NULL
                }

                $attributes[$name] = self::dummyValueForColumn($column);
            }

            $instance->fill($attributes);
            $instance->save();

            return $instance;
        } catch (Throwable) {
        }

        return null;
    }

    private static function dummyValueForColumn(array $column): mixed
    {
        $type = strtolower($column['type_name'] ?? $column['type'] ?? 'varchar');

        return match (true) {
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'])   => 1,
            in_array($type, ['float', 'double', 'decimal', 'numeric', 'real'])                  => 1.0,
            in_array($type, ['bool', 'boolean'])                                                => true,
            in_array($type, ['json', 'jsonb'])                                                  => '{}',
            in_array($type, ['date'])                                                           => now()->toDateString(),
            in_array($type, ['datetime', 'timestamp'])                                          => now()->toDateTimeString(),
            str_contains($type, 'char') || str_contains($type, 'text')                         => 'test',
            default                                                                             => 'test',
        };
    }

    private function loginWithSeededUser(): void
    {
        try {
            $userModel = config('auth.providers.users.model', \App\Models\User::class);

            if (!class_exists($userModel)) {
                return;
            }

            $user = method_exists($userModel, 'factory')
                ? $userModel::factory()->create()
                : $userModel::first();

            if ($user !== null) {
                Auth::setUser($user);
            }
        } catch (Throwable) {
        }
    }

    private static function jsonValueToTypeString(mixed $value): string
    {
        return match (true) {
            $value === null                                        => 'unknown',
            is_bool($value)                                       => 'boolean',
            is_int($value) || is_float($value)                    => 'number',
            is_string($value)                                     => 'string',
            is_array($value) && empty($value)                     => 'unknown[]',
            is_array($value) && array_is_list($value)             => self::jsonValueToTypeString($value[0]) . '[]',
            is_array($value)                                      => self::jsonObjectToTypeString($value),
            default                                               => 'unknown',
        };
    }

    private static function jsonObjectToTypeString(array $object): string
    {
        $parts = [];

        foreach ($object as $key => $value) {
            $parts[] = "$key: " . self::jsonValueToTypeString($value);
        }

        return '{ ' . implode('; ', $parts) . ' }';
    }

    // -------------------------------------------------------------------------
    // Layer 1: return type annotation
    // -------------------------------------------------------------------------

    private function resolveFromReturnType(ReflectionMethod $method): ?array
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType) {
            return null;
        }

        $className = $returnType->getName();

        if (!class_exists($className) || $className === AnonymousResourceCollection::class) {
            return null;
        }

        try {
            if ((new ReflectionClass($className))->isAbstract()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        if (is_subclass_of($className, ResourceCollection::class)) {
            return $this->resolveViaCollectionClass($className);
        }

        if (is_subclass_of($className, JsonResource::class)) {
            return $this->resolveSingle($className);
        }

        if ($this->isDataClass($className)) {
            return $this->resolveDataClass($className, false);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Layer 2: token-based method body analysis
    // -------------------------------------------------------------------------

    private function resolveFromMethodBody(ReflectionMethod $method): ?array
    {
        $filename  = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine   = $method->getEndLine();

        if ($filename === false || $startLine === false || $endLine === false) {
            return null;
        }

        $lines = @file($filename, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return null;
        }

        $body   = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        $useMap = self::resolveUseStatements($filename);

        try {
            $tokens = self::tokenize($body);
        } catch (Throwable) {
            return null;
        }

        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (self::tokId($tokens[$i]) !== T_RETURN) {
                continue;
            }

            $j = $i + 1;

            // Try direct resource return first (SomeResource::make/collection, new SomeResource)
            $direct = $this->tryDirectResource($tokens, $j, $useMap);
            if ($direct !== null) {
                return $direct;
            }

            // Try composite array response (response()->json([...]) or service->method([...]))
            $composite = $this->tryCompositeResponse($tokens, $j, $n, $useMap, $method->getDeclaringClass()->getName());
            if ($composite !== null) {
                return $composite;
            }
        }

        return null;
    }

    private function tryDirectResource(array $tokens, int $pos, array $useMap): ?array
    {
        $tok = $tokens[$pos] ?? null;
        if ($tok === null) {
            return null;
        }

        // SomeResource::make( or SomeResource::collection(
        if (self::tokId($tok) === T_STRING
            && self::tokId($tokens[$pos + 1] ?? null) === T_DOUBLE_COLON
            && self::tokId($tokens[$pos + 2] ?? null) === T_STRING
        ) {
            $fqn    = $useMap[$tok[1]] ?? $tok[1];
            $method = ($tokens[$pos + 2])[1];

            if (class_exists($fqn) && is_subclass_of($fqn, JsonResource::class)) {
                if ($method === 'collection') {
                    return $this->resolveViaCollectionMethod($fqn);
                }
                if ($method === 'make') {
                    return $this->resolveSingle($fqn);
                }
            }

            if ($this->isDataClass($fqn)) {
                if ($method === 'collect') {
                    return $this->resolveDataClass($fqn, true);
                }
                if (in_array($method, ['from', 'make'])) {
                    return $this->resolveDataClass($fqn, false);
                }
            }
        }

        // new SomeResource(
        if (self::tokId($tok) === T_NEW
            && self::tokId($tokens[$pos + 1] ?? null) === T_STRING
        ) {
            $fqn = $useMap[($tokens[$pos + 1])[1]] ?? ($tokens[$pos + 1])[1];

            if (class_exists($fqn) && is_subclass_of($fqn, JsonResource::class)) {
                return is_subclass_of($fqn, ResourceCollection::class)
                    ? $this->resolveViaCollectionClass($fqn)
                    : $this->resolveSingle($fqn);
            }

            if ($this->isDataClass($fqn)) {
                return $this->resolveDataClass($fqn, false);
            }
        }

        return null;
    }

    private function tryCompositeResponse(array $tokens, int $start, int $end, array $useMap, string $controllerClass): ?array
    {
        // Walk forward from $start to find the first '[' that is an argument to a call.
        // Track the method name immediately before the enclosing '(' for wrap detection.
        $parenDepth        = 0;
        $methodBeforeParen = null;  // method name of the call that contains the array
        $isDirectJson      = false; // response()->json( pattern

        for ($i = $start; $i < $end; $i++) {
            $char = self::tokChar($tokens[$i]);
            $id   = self::tokId($tokens[$i]);

            if ($char === '(') {
                // Record the method name that opened this paren
                $prev = $tokens[$i - 1] ?? null;
                if (self::tokId($prev) === T_STRING) {
                    $name = $prev[1];
                    if ($parenDepth === 0) {
                        $methodBeforeParen = $name;
                        $isDirectJson      = ($name === 'json');
                    }
                }
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth--;
            } elseif ($char === '[') {
                // Found the data array
                $arrayShape = $this->parseArrayTokens($tokens, $i, $end, $useMap);

                if (empty($arrayShape)) {
                    return null;
                }

                $wrap = $this->detectWrap(
                    $isDirectJson ? 'json' : $methodBeforeParen,
                    $tokens,
                    $start,
                    $controllerClass,
                );

                return [
                    'wrap'       => $wrap,
                    'collection' => false,
                    'shape'      => $arrayShape,
                ];
            } elseif ($char === ';') {
                break;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Array token parser
    // -------------------------------------------------------------------------

    /**
     * Parse a PHP array literal starting at the '[' token at $pos.
     * Returns ['key' => 'typescript_type', ...] for string-keyed entries.
     *
     * @return array<string, string>
     */
    private function parseArrayTokens(array $tokens, int $pos, int $limit, array $useMap): array
    {
        $shape = [];
        $i     = $pos + 1; // skip opening '['
        $n     = $limit;

        while ($i < $n) {
            $tok  = $tokens[$i];
            $char = self::tokChar($tok);

            if ($char === ']') {
                break;
            }

            // Only handle string-keyed entries: 'key' => value
            if (self::tokId($tok) === T_CONSTANT_ENCAPSED_STRING) {
                $key  = trim($tok[1], "'\"");
                $next = $tokens[$i + 1] ?? null;

                if (self::tokId($next) === T_DOUBLE_ARROW) {
                    $i += 2; // skip key + =>

                    // Collect value tokens until the next ',' or ']' at depth 0
                    $valueTokens = [];
                    $depth       = 0;

                    while ($i < $n) {
                        $vtok  = $tokens[$i];
                        $vchar = self::tokChar($vtok);

                        if ($vchar === '(' || $vchar === '[') {
                            $depth++;
                        } elseif ($vchar === ')' || $vchar === ']') {
                            if ($depth === 0) {
                                break;
                            }
                            $depth--;
                        } elseif ($vchar === ',' && $depth === 0) {
                            $i++; // skip ','
                            break;
                        }

                        $valueTokens[] = $vtok;
                        $i++;
                    }

                    $shape[$key] = $this->inferTokensType($valueTokens, $useMap);
                    continue;
                }
            }

            $i++;
        }

        return $shape;
    }

    /**
     * Determine the TypeScript type of a sequence of tokens representing a PHP expression.
     */
    private function inferTokensType(array $valueTokens, array $useMap): string
    {
        $n = count($valueTokens);

        for ($i = 0; $i < $n; $i++) {
            $tok = $valueTokens[$i];

            // ClassName::collection( or ClassName::make(
            if (self::tokId($tok) === T_STRING
                && ctype_upper($tok[1][0] ?? '')
                && self::tokId($valueTokens[$i + 1] ?? null) === T_DOUBLE_COLON
                && self::tokId($valueTokens[$i + 2] ?? null) === T_STRING
            ) {
                $fqn    = $useMap[$tok[1]] ?? $tok[1];
                $method = ($valueTokens[$i + 2])[1];

                if (class_exists($fqn) && is_subclass_of($fqn, JsonResource::class)) {
                    $result = $method === 'collection'
                        ? $this->resolveViaCollectionMethod($fqn)
                        : $this->resolveSingle($fqn);

                    if ($result !== null) {
                        return self::resultToTypeString($result);
                    }
                }

                if ($this->isDataClass($fqn)) {
                    $result = $method === 'collect'
                        ? $this->resolveDataClass($fqn, true)
                        : $this->resolveDataClass($fqn, false);

                    if ($result !== null) {
                        return self::resultToTypeString($result);
                    }
                }
            }

            // new ClassName(
            if (self::tokId($tok) === T_NEW
                && self::tokId($valueTokens[$i + 1] ?? null) === T_STRING
            ) {
                $fqn = $useMap[($valueTokens[$i + 1])[1]] ?? ($valueTokens[$i + 1])[1];

                if (class_exists($fqn) && is_subclass_of($fqn, JsonResource::class)) {
                    $result = is_subclass_of($fqn, ResourceCollection::class)
                        ? $this->resolveViaCollectionClass($fqn)
                        : $this->resolveSingle($fqn);

                    if ($result !== null) {
                        return self::resultToTypeString($result);
                    }
                }

                if ($this->isDataClass($fqn)) {
                    $result = $this->resolveDataClass($fqn, false);
                    if ($result !== null) {
                        return self::resultToTypeString($result);
                    }
                }
            }
        }

        return 'unknown';
    }

    // -------------------------------------------------------------------------
    // Wrap detection
    // -------------------------------------------------------------------------

    private function detectWrap(?string $methodName, array $tokens, int $start, string $controllerClass): ?string
    {
        // response()->json([...]) — no extra wrapping; array IS the response
        if ($methodName === null || $methodName === 'json') {
            return null;
        }

        // Find the object that owns this method in the return expression,
        // resolve its type, call method([]) and detect the wrap key.
        try {
            return $this->detectWrapByCall($methodName, $tokens, $start, $controllerClass);
        } catch (Throwable) {
            return null;
        }
    }

    private function detectWrapByCall(string $methodName, array $tokens, int $start, string $controllerClass): ?string
    {
        // Find '->methodName' preceded by a property/variable name
        $n          = count($tokens);
        $objectProp = null;

        for ($i = $start; $i < $n; $i++) {
            if (self::tokId($tokens[$i]) === T_STRING && $tokens[$i][1] === $methodName) {
                $prev = $tokens[$i - 1] ?? null;
                $prev2 = $tokens[$i - 2] ?? null;

                if (self::tokId($prev) === T_OBJECT_OPERATOR && self::tokId($prev2) === T_STRING) {
                    $objectProp = $prev2[1];
                }
                break;
            }
        }

        if ($objectProp === null) {
            return null;
        }

        $propType = $this->resolvePropertyType($controllerClass, $objectProp);
        if ($propType === null) {
            return null;
        }

        $instance = $this->container->make($propType);
        $response = $instance->$methodName([]);

        $decoded = json_decode($response->getContent(), true);
        if (!is_array($decoded)) {
            return null;
        }

        // The wrap key is the one whose value is an empty array/object
        foreach ($decoded as $key => $val) {
            if (is_array($val) && empty($val)) {
                return (string) $key;
            }
        }

        return null;
    }

    private function resolvePropertyType(string $className, string $propertyName): ?string
    {
        try {
            $ref = new ReflectionClass($className);

            // Check constructor parameters (most common — constructor promotion)
            foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
                if ($param->getName() === $propertyName) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                        return $type->getName();
                    }
                }
            }

            // Check class properties
            if ($ref->hasProperty($propertyName)) {
                $type = $ref->getProperty($propertyName)->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    return $type->getName();
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Resource resolution (single / collection)
    // -------------------------------------------------------------------------

    private function resolveSingle(string $resourceClass): ?array
    {
        $shape = $this->extractShape($resourceClass);

        if ($shape === null) {
            return null;
        }

        return [
            'wrap'       => $resourceClass::$wrap,
            'collection' => false,
            'shape'      => $shape,
        ];
    }

    private function resolveViaCollectionMethod(string $itemClass): ?array
    {
        $shape = $this->extractShape($itemClass);

        if ($shape === null) {
            return null;
        }

        return [
            'wrap'       => $itemClass::$wrap,
            'collection' => true,
            'shape'      => $shape,
        ];
    }

    private function resolveViaCollectionClass(string $collectionClass): ?array
    {
        $itemClass = $this->findCollectionItemClass($collectionClass);

        if ($itemClass === null) {
            return null;
        }

        $shape = $this->extractShape($itemClass);

        if ($shape === null) {
            return null;
        }

        return [
            'wrap'       => $collectionClass::$wrap,
            'collection' => true,
            'shape'      => $shape,
        ];
    }

    private function findCollectionItemClass(string $collectionClass): ?string
    {
        try {
            $ref = new ReflectionClass($collectionClass);

            if ($ref->hasProperty('collects')) {
                $collects = $ref->getProperty('collects')->getDefaultValue();

                if (is_string($collects) && class_exists($collects) && is_subclass_of($collects, JsonResource::class)) {
                    return $collects;
                }
            }
        } catch (Throwable) {
        }

        $baseName  = class_basename($collectionClass);
        $namespace = (string) substr($collectionClass, 0, (int) strrpos($collectionClass, '\\'));

        foreach ([
            rtrim($baseName, 'Collection') . 'Resource',
            rtrim($baseName, 'Collection'),
        ] as $candidate) {
            $fqn = $namespace !== '' ? "$namespace\\$candidate" : $candidate;

            if (class_exists($fqn) && is_subclass_of($fqn, JsonResource::class)) {
                return $fqn;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Spatie Laravel Data resolution
    // -------------------------------------------------------------------------

    private function isDataClass(string $className): bool
    {
        return class_exists('Spatie\\LaravelData\\Data')
            && class_exists($className)
            && is_subclass_of($className, 'Spatie\\LaravelData\\Data');
    }

    private function resolveDataClass(string $dataClass, bool $collection): ?array
    {
        // Prefer a reference to the transformer-generated TypeScript type when available
        $transformer = $this->tryTransformerType($dataClass);
        if ($transformer !== null) {
            return [
                'typescript_type' => $transformer['type'],
                'typescript_file' => $transformer['file'],
                'wrap'            => null,
                'collection'      => $collection,
                'shape'           => [],
            ];
        }

        $shape = $this->extractDataShape($dataClass);

        if ($shape === null) {
            return null;
        }

        return [
            'wrap'       => null,
            'collection' => $collection,
            'shape'      => $shape,
        ];
    }

    /**
     * If spatie/laravel-typescript-transformer has already generated a TypeScript
     * definitions file and it contains a type for this Data class, return the
     * type name and the absolute path to the generated file so the compiler
     * can add a proper import.
     *
     * @return array{type: string, file: string}|null
     */
    private function tryTransformerType(string $dataClass): ?array
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

            $typeName = class_basename($dataClass);

            // Match: export type TypeName or export interface TypeName
            if (preg_match('/\bexport\s+(?:type|interface)\s+' . preg_quote($typeName, '/') . '\b/', $content)) {
                return ['type' => $typeName, 'file' => realpath($outputFile) ?: $outputFile];
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Extract the TypeScript shape from a Spatie Data class's public typed properties.
     *
     * @return array<string, string>|null
     */
    private function extractDataShape(string $dataClass): ?array
    {
        try {
            $ref   = new ReflectionClass($dataClass);
            $shape = [];

            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }

                $shape[$prop->getName()] = $this->dataPropertyToTs($prop);
            }

            return $shape;
        } catch (Throwable) {
            return null;
        }
    }

    private function dataPropertyToTs(ReflectionProperty $prop): string
    {
        $type = $prop->getType();

        if ($type === null) {
            return 'unknown';
        }

        return $this->reflectionTypeToTs($type, $prop);
    }

    private function reflectionTypeToTs(\ReflectionType $type, ReflectionProperty $prop): string
    {
        if ($type instanceof ReflectionUnionType) {
            $nullable = false;
            $parts    = [];

            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && $t->getName() === 'null') {
                    $nullable = true;
                    continue;
                }
                if ($t instanceof ReflectionNamedType) {
                    $ts = $this->namedTypeToTs($t, $prop);
                    if ($ts !== 'unknown') {
                        $parts[] = $ts;
                    }
                }
            }

            $result = $parts !== [] ? implode(' | ', array_unique($parts)) : 'unknown';
            return $nullable ? $result . ' | null' : $result;
        }

        if ($type instanceof ReflectionIntersectionType) {
            return 'unknown';
        }

        if ($type instanceof ReflectionNamedType) {
            $ts = $this->namedTypeToTs($type, $prop);
            return $type->allowsNull() && $ts !== 'unknown' ? $ts . ' | null' : $ts;
        }

        return 'unknown';
    }

    private function namedTypeToTs(ReflectionNamedType $type, ReflectionProperty $prop): string
    {
        return match ($type->getName()) {
            'int', 'float'             => 'number',
            'string'                   => 'string',
            'bool'                     => 'boolean',
            'array'                    => $this->resolveArrayPropType($prop),
            'mixed', 'void', 'null'    => 'unknown',
            default                    => $this->classTypeToTs($type->getName(), $prop),
        };
    }

    private function resolveArrayPropType(ReflectionProperty $prop): string
    {
        foreach ($prop->getAttributes() as $attr) {
            if ($attr->getName() === 'Spatie\\LaravelData\\Attributes\\DataCollectionOf') {
                $itemClass = $attr->getArguments()[0] ?? null;
                if (is_string($itemClass) && $this->isDataClass($itemClass)) {
                    $shape = $this->extractDataShape($itemClass);
                    if ($shape !== null) {
                        return self::buildShapeTypeString($shape) . '[]';
                    }
                }
            }
        }

        return 'unknown[]';
    }

    private function classTypeToTs(string $className, ReflectionProperty $prop): string
    {
        if (!class_exists($className)) {
            return 'unknown';
        }

        // Spatie DataCollection typed property — look for DataCollectionOf attribute
        if (class_exists('Spatie\\LaravelData\\DataCollection')
            && is_a($className, 'Spatie\\LaravelData\\DataCollection', true)
        ) {
            foreach ($prop->getAttributes() as $attr) {
                if ($attr->getName() === 'Spatie\\LaravelData\\Attributes\\DataCollectionOf') {
                    $itemClass = $attr->getArguments()[0] ?? null;
                    if (is_string($itemClass) && $this->isDataClass($itemClass)) {
                        $shape = $this->extractDataShape($itemClass);
                        if ($shape !== null) {
                            return self::buildShapeTypeString($shape) . '[]';
                        }
                    }
                }
            }

            return 'unknown[]';
        }

        // Nested Spatie Data object
        if ($this->isDataClass($className)) {
            $shape = $this->extractDataShape($className);
            if ($shape !== null) {
                return self::buildShapeTypeString($shape);
            }
        }

        // Date/time types always serialize as strings
        if (is_a($className, 'DateTimeInterface', true)) {
            return 'string';
        }

        // Laravel Collection
        if (is_a($className, 'Illuminate\\Support\\Collection', true)) {
            return 'unknown[]';
        }

        return 'unknown';
    }

    // -------------------------------------------------------------------------
    // Shape extraction via mock call
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>|null
     */
    private function extractShape(string $resourceClass): ?array
    {
        try {
            $mock     = self::createMockModel();
            $instance = new $resourceClass($mock);
            $resolved = $instance->resolve(new Request());

            return self::mapShape((array) $resolved);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<mixed> $resolved
     * @return array<string, string>
     */
    private static function mapShape(array $resolved): array
    {
        $shape = [];

        foreach ($resolved as $key => $value) {
            $shape[(string) $key] = self::mapValue($value);
        }

        return $shape;
    }

    private static function mapValue(mixed $value): string
    {
        return match (true) {
            is_int($value), is_float($value)                                => 'number',
            is_string($value)                                               => 'string',
            is_bool($value)                                                 => 'boolean',
            is_array($value) && array_is_list($value) && !empty($value)    => self::mapValue($value[0]) . '[]',
            is_array($value) && !array_is_list($value) && !empty($value)   => self::buildInlineObject($value),
            default                                                         => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $assoc
     */
    private static function buildInlineObject(array $assoc): string
    {
        $parts = [];

        foreach ($assoc as $key => $val) {
            $parts[] = "$key: " . self::mapValue($val);
        }

        return '{ ' . implode('; ', $parts) . ' }';
    }

    private static function createMockModel(): object
    {
        return new class {
            public function __get(string $key): mixed
            {
                return null;
            }

            public function __isset(string $key): bool
            {
                return true;
            }

            public function __call(string $method, array $args): mixed
            {
                return null;
            }

            public function getAttributes(): array
            {
                return [];
            }

            public function relationLoaded(string $relation): bool
            {
                return false;
            }

            public function hasAppended(string $attribute): bool
            {
                return false;
            }

            public function toArray(): array
            {
                return [];
            }
        };
    }

    // -------------------------------------------------------------------------
    // Type string helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a resolved resource result to a TypeScript type string.
     * Used when a resource appears as a value inside a composite response array.
     * The resource's own $wrap is intentionally ignored here: $wrap only applies
     * when the resource IS the HTTP response, not when it is a value in a parent array.
     */
    private static function resultToTypeString(array $result): string
    {
        $shapeStr = self::buildShapeTypeString($result['shape']);

        return $result['collection'] ? $shapeStr . '[]' : $shapeStr;
    }

    private static function buildShapeTypeString(array $shape): string
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

    // -------------------------------------------------------------------------
    // Use-statement resolution
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private static function resolveUseStatements(string $filename): array
    {
        $contents = @file_get_contents($filename);

        if ($contents === false) {
            return [];
        }

        preg_match_all(
            '/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        $map = [];

        foreach ($matches as $m) {
            $shortName       = !empty($m[2]) ? $m[2] : class_basename($m[1]);
            $map[$shortName] = $m[1];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Token helpers
    // -------------------------------------------------------------------------

    private static function tokenize(string $body): array
    {
        return array_values(array_filter(
            token_get_all('<?php ' . $body),
            fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
    }

    private static function tokId(mixed $tok): int|false
    {
        return is_array($tok) ? $tok[0] : false;
    }

    private static function tokChar(mixed $tok): string
    {
        return is_string($tok) ? $tok : '';
    }
}
