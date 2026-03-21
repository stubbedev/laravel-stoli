<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Stringable;
use Throwable;
use UnitEnum;

final readonly class RequestParameterResolver
{
    public function __construct(
        private Container $container,
    )
    {
    }
    /**
     * @return array<string, array{type: string, required: bool}>|null
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
            $method = '__invoke';
        }

        if (!class_exists($controller)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (!class_exists($className) || !is_subclass_of($className, FormRequest::class)) {
                continue;
            }

            return $this->extractRules($className);
        }

        return null;
    }

    /**
     * @return array<string, array{type: string, required: bool}>|null
     */
    private function extractRules(string $formRequestClass): ?array
    {
        try {
            $instance = $this->createFormRequest($formRequestClass);
            $rules = $instance->rules();

            $tree = self::buildTree($rules);

            return self::treeToParams($tree);
        } catch (Throwable) {
            return null;
        }
    }

    private function createFormRequest(string $formRequestClass): FormRequest
    {
        $reflection = new ReflectionClass($formRequestClass);
        $constructor = $reflection->getConstructor();

        $instance = $constructor === null || $constructor->getNumberOfParameters() === 0
            ? new $formRequestClass()
            : $reflection->newInstanceArgs(
                array_map($this->resolveParameter(...), $constructor->getParameters())
            );

        $instance->setContainer($this->container);

        return $instance;
    }

    private function resolveParameter(ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            try {
                return $this->container->make($type->getName());
            } catch (Throwable) {
                // fall through
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        return match ($typeName) {
            'array' => [],
            'string' => '',
            'int', 'float' => 0,
            'bool' => false,
            default => null,
        };
    }

    private static function buildTree(array $rules): array
    {
        $tree = [];

        foreach ($rules as $key => $fieldRules) {
            $segments = explode('.', $key);
            $node = &$tree;
            $lastIndex = count($segments) - 1;

            foreach ($segments as $i => $segment) {
                if (!isset($node[$segment])) {
                    $node[$segment] = ['rules' => null, 'children' => []];
                }

                if ($i === $lastIndex) {
                    $node[$segment]['rules'] = $fieldRules;
                } else {
                    $node = &$node[$segment]['children'];
                }
            }

            unset($node);
        }

        return $tree;
    }

    private static function treeToParams(array $tree): array
    {
        $params = [];

        foreach ($tree as $key => $node) {
            $params[$key] = self::nodeToParam($node);
        }

        return $params;
    }

    private static function nodeToParam(array $node): array
    {
        $normalized = $node['rules'] !== null ? self::flattenRules($node['rules']) : [];
        $required = in_array('required', $normalized, true);

        return [
            'type'     => self::nodeToTypeString($node),
            'required' => $required,
        ];
    }

    private static function nodeToTypeString(array $node): string
    {
        $normalized = $node['rules'] !== null ? self::flattenRules($node['rules']) : [];
        $nullable = in_array('nullable', $normalized, true);
        $children = $node['children'];

        // Leaf node
        if (empty($children)) {
            return self::resolveType($normalized);
        }

        // Wildcard child -> array type
        if (isset($children['*'])) {
            $wildcard = $children['*'];

            // Simple array of primitives: tags.* => string -> string[]
            if (empty($wildcard['children'])) {
                $wildcardNormalized = $wildcard['rules'] !== null
                    ? self::flattenRules($wildcard['rules'])
                    : [];
                $elementType = self::resolveType($wildcardNormalized);
                $type = str_contains($elementType, ' | ')
                    ? "({$elementType})[]"
                    : "{$elementType}[]";
            } else {
                // Array of objects: meta.*.id, meta.*.name
                $type = self::buildObjectType($wildcard['children']) . '[]';
            }
        } else {
            // Named children -> inline object type
            $type = self::buildObjectType($children);
        }

        if ($nullable) {
            $type .= ' | null';
        }

        return $type;
    }

    private static function buildObjectType(array $children): string
    {
        $properties = [];

        foreach ($children as $key => $child) {
            if ($key === '*') {
                continue; // wildcard keys can't be expressed as named properties
            }
            $childNormalized = $child['rules'] !== null ? self::flattenRules($child['rules']) : [];
            $optional = in_array('required', $childNormalized, true) ? '' : '?';
            $type = self::nodeToTypeString($child);

            $properties[] = "$key$optional: $type";
        }

        return '{ ' . implode('; ', $properties) . ' }';
    }

    /**
     * @return array<string>
     */
    private static function flattenRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (!is_array($rules)) {
            return array_filter([self::ruleToString($rules)]);
        }

        $flattened = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                foreach (explode('|', $rule) as $r) {
                    $flattened[] = $r;
                }
            } else {
                $str = self::ruleToString($rule);
                if ($str !== null) {
                    $flattened[] = $str;
                }
            }
        }

        return $flattened;
    }

    private static function ruleToString(mixed $rule): ?string
    {
        if ($rule instanceof In) {
            return (string) $rule;
        }

        if ($rule instanceof Enum) {
            return self::enumToInRule($rule);
        }

        if ($rule instanceof Stringable) {
            return (string) $rule;
        }

        return null;
    }

    private static function enumToInRule(Enum $rule): ?string
    {
        try {
            $reflection = new ReflectionClass($rule);
            $property = $reflection->getProperty('type');
            $enumClass = $property->getValue($rule);

            if (!enum_exists($enumClass)) {
                return null;
            }

            $cases = array_map(
                fn(UnitEnum $case) => $case instanceof BackedEnum ? $case->value : $case->name,
                $enumClass::cases()
            );

            return 'in:' . implode(',', $cases);
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveType(array $rules): string
    {
        $types = [];
        $nullable = false;

        foreach ($rules as $rule) {
            if ($rule === 'nullable') {
                $nullable = true;
                continue;
            }

            $mapped = self::mapToTypeScript($rule);

            if ($mapped !== null) {
                $types[] = $mapped;
            }
        }

        if (empty($types)) {
            $types[] = 'unknown';
        }

        if ($nullable) {
            $types[] = 'null';
        }

        return implode(' | ', array_unique($types));
    }

    private static function mapToTypeScript(string $rule): ?string
    {
        return match (true) {
            // String types
            in_array($rule, [
                'string', 'email', 'url', 'active_url',
                'ip', 'ipv4', 'ipv6',
                'uuid', 'ulid',
                'alpha', 'alpha_num', 'alpha_dash', 'ascii',
                'lowercase', 'uppercase',
                'hex_color', 'mac_address',
                'json', 'date', 'timezone',
                'password', 'confirmed', 'current_password',
            ], true),
            str_starts_with($rule, 'date_format:'),
            str_starts_with($rule, 'date_equals:'),
            str_starts_with($rule, 'regex:'),
            str_starts_with($rule, 'not_regex:'),
            str_starts_with($rule, 'after:'),
            str_starts_with($rule, 'after_or_equal:'),
            str_starts_with($rule, 'before:'),
            str_starts_with($rule, 'before_or_equal:'),
            str_starts_with($rule, 'starts_with:'),
            str_starts_with($rule, 'ends_with:'),
            str_starts_with($rule, 'doesnt_start_with:'),
            str_starts_with($rule, 'doesnt_end_with:'),
            str_starts_with($rule, 'current_password:') => 'string',

            // Number types
            in_array($rule, ['integer', 'numeric'], true),
            str_starts_with($rule, 'decimal:'),
            str_starts_with($rule, 'digits:'),
            str_starts_with($rule, 'digits_between:'),
            str_starts_with($rule, 'min_digits:'),
            str_starts_with($rule, 'max_digits:'),
            str_starts_with($rule, 'multiple_of:') => 'number',

            // Boolean types
            in_array($rule, ['boolean', 'accepted', 'declined'], true),
            str_starts_with($rule, 'accepted_if:'),
            str_starts_with($rule, 'declined_if:') => 'boolean',

            // Sequential array types
            $rule === 'list',
            $rule === 'distinct' || str_starts_with($rule, 'distinct:'),
            str_starts_with($rule, 'contains:'),
            str_starts_with($rule, 'doesnt_contain:') => 'unknown[]',

            // Object/map array types
            $rule === 'array',
            str_starts_with($rule, 'required_array_keys:'),
            str_starts_with($rule, 'in_array_keys:') => 'Record<string, unknown>',

            // File types
            in_array($rule, ['file', 'image'], true),
            str_starts_with($rule, 'mimes:'),
            str_starts_with($rule, 'mimetypes:'),
            str_starts_with($rule, 'dimensions:'),
            str_starts_with($rule, 'extensions:'),
            str_starts_with($rule, 'encoding:') => 'File',

            // Enum / union of literals
            str_starts_with($rule, 'in:') => self::parseInRule($rule),

            // Everything else (modifiers, constraints) -> no type information
            default => null,
        };
    }

    private static function parseInRule(string $rule): string
    {
        $values = str_getcsv(substr($rule, 3));

        return implode(' | ', array_map(
            fn(string $value) => "'" . trim($value) . "'",
            $values,
        ));
    }
}
