<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Resolvers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionIntersectionType;
use Throwable;

/**
 * Shared helper methods used by multiple ReturnTypeResolver strategy classes.
 * Extracted as a trait to avoid duplication across the three layer classes.
 */
trait ReturnTypeResolverHelpers
{
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
    // Spatie Laravel Data helpers
    // -------------------------------------------------------------------------

    private function isDataClass(string $className): bool
    {
        return class_exists('Spatie\\LaravelData\\Data')
            && class_exists($className)
            && is_subclass_of($className, 'Spatie\\LaravelData\\Data');
    }

    private function resolveDataClass(string $dataClass, bool $collection): ?array
    {
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

            if (preg_match('/\bexport\s+(?:type|interface)\s+' . preg_quote($typeName, '/') . '\b/', $content)) {
                return ['type' => $typeName, 'file' => realpath($outputFile) ?: $outputFile];
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
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

        if ($this->isDataClass($className)) {
            $shape = $this->extractDataShape($className);
            if ($shape !== null) {
                return self::buildShapeTypeString($shape);
            }
        }

        if (is_a($className, 'DateTimeInterface', true)) {
            return 'string';
        }

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
