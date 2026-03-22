<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Resolvers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Layer 2: Resolve a route's response type by tokenizing the controller method's
 * source code and pattern-matching return statements.
 *
 * Recognises:
 *  - SomeResource::make(...)
 *  - SomeResource::collection(...)
 *  - new SomeResource(...)
 *  - response()->json([...]) — composite array responses
 *  - $this->service->method([...]) — with wrap-key detection via reflection
 */
final readonly class TokenReturnTypeResolver
{
    use ReturnTypeResolverHelpers;

    public function __construct(private Container $container)
    {
    }

    public function resolve(ReflectionMethod $method): ?array
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

            $direct = $this->tryDirectResource($tokens, $j, $useMap);
            if ($direct !== null) {
                return $direct;
            }

            $composite = $this->tryCompositeResponse(
                $tokens, $j, $n, $useMap,
                $method->getDeclaringClass()->getName()
            );
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
        $parenDepth        = 0;
        $methodBeforeParen = null;
        $isDirectJson      = false;

        for ($i = $start; $i < $end; $i++) {
            $char = self::tokChar($tokens[$i]);

            if ($char === '(') {
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

    /**
     * Parse a PHP array literal starting at the '[' token at $pos.
     *
     * @return array<string, string>
     */
    private function parseArrayTokens(array $tokens, int $pos, int $limit, array $useMap): array
    {
        $shape = [];
        $i     = $pos + 1;
        $n     = $limit;

        while ($i < $n) {
            $tok  = $tokens[$i];
            $char = self::tokChar($tok);

            if ($char === ']') {
                break;
            }

            if (self::tokId($tok) === T_CONSTANT_ENCAPSED_STRING) {
                $key  = trim($tok[1], "'\"");
                $next = $tokens[$i + 1] ?? null;

                if (self::tokId($next) === T_DOUBLE_ARROW) {
                    $i += 2;

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
                            $i++;
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

    private function inferTokensType(array $valueTokens, array $useMap): string
    {
        $n = count($valueTokens);

        for ($i = 0; $i < $n; $i++) {
            $tok = $valueTokens[$i];

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

    private function detectWrap(?string $methodName, array $tokens, int $start, string $controllerClass): ?string
    {
        if ($methodName === null || $methodName === 'json') {
            return null;
        }

        try {
            return $this->detectWrapByCall($methodName, $tokens, $start, $controllerClass);
        } catch (Throwable) {
            return null;
        }
    }

    private function detectWrapByCall(string $methodName, array $tokens, int $start, string $controllerClass): ?string
    {
        $n          = count($tokens);
        $objectProp = null;

        for ($i = $start; $i < $n; $i++) {
            if (self::tokId($tokens[$i]) === T_STRING && $tokens[$i][1] === $methodName) {
                $prev  = $tokens[$i - 1] ?? null;
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

            foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
                if ($param->getName() === $propertyName) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                        return $type->getName();
                    }
                }
            }

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
}
