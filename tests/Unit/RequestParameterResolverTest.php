<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StubbeDev\LaravelStoli\RequestParameterResolver;

/**
 * Tests for the rule-to-TypeScript type mapping inside RequestParameterResolver.
 *
 * We test the public surface indirectly via a FormRequest stub and the `resolve()`
 * method, but since `resolve()` requires a real Laravel route + container, the
 * private helpers are exercised via the integration tests below using an in-process
 * container.
 *
 * For the pure-mapping logic we test `mapToTypeScript` via reflection (it's private
 * static), which keeps the tests focused and fast.
 */
final class RequestParameterResolverTest extends TestCase
{
    private \ReflectionMethod $mapMethod;

    protected function setUp(): void
    {
        $ref             = new ReflectionClass(RequestParameterResolver::class);
        $this->mapMethod = $ref->getMethod('mapToTypeScript');
        $this->mapMethod->setAccessible(true);
    }

    private function map(string $rule): ?string
    {
        return $this->mapMethod->invoke(null, $rule);
    }

    // -------------------------------------------------------------------------
    // String types
    // -------------------------------------------------------------------------

    /** @dataProvider stringRules */
    public function test_string_rules_map_to_string(string $rule): void
    {
        self::assertSame('string', $this->map($rule));
    }

    public static function stringRules(): array
    {
        return array_map(
            fn($r) => [$r],
            [
                'string', 'email', 'url', 'active_url',
                'uuid', 'ulid',
                'alpha', 'alpha_num', 'alpha_dash', 'ascii',
                'lowercase', 'uppercase',
                'hex_color', 'mac_address',
                'json', 'date', 'timezone',
                'date_format:Y-m-d',
                'starts_with:foo',
                'ends_with:bar',
                'regex:/^[a-z]+$/',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Number types
    // -------------------------------------------------------------------------

    /** @dataProvider numberRules */
    public function test_number_rules_map_to_number(string $rule): void
    {
        self::assertSame('number', $this->map($rule));
    }

    public static function numberRules(): array
    {
        return array_map(fn($r) => [$r], [
            'integer', 'numeric',
            'decimal:2', 'digits:5', 'digits_between:1,10',
            'min_digits:1', 'max_digits:10',
            'multiple_of:3',
        ]);
    }

    // -------------------------------------------------------------------------
    // Boolean types
    // -------------------------------------------------------------------------

    /** @dataProvider boolRules */
    public function test_boolean_rules_map_to_boolean(string $rule): void
    {
        self::assertSame('boolean', $this->map($rule));
    }

    public static function boolRules(): array
    {
        return array_map(fn($r) => [$r], [
            'boolean', 'accepted', 'declined',
            'accepted_if:other,1',
            'declined_if:other,0',
        ]);
    }

    // -------------------------------------------------------------------------
    // Array / object types
    // -------------------------------------------------------------------------

    public function test_list_maps_to_unknown_array(): void
    {
        self::assertSame('unknown[]', $this->map('list'));
    }

    public function test_distinct_maps_to_unknown_array(): void
    {
        self::assertSame('unknown[]', $this->map('distinct'));
    }

    public function test_array_maps_to_record(): void
    {
        self::assertSame('Record<string, unknown>', $this->map('array'));
    }

    // -------------------------------------------------------------------------
    // File types
    // -------------------------------------------------------------------------

    public function test_file_maps_to_File(): void
    {
        self::assertSame('File', $this->map('file'));
    }

    public function test_image_maps_to_File(): void
    {
        self::assertSame('File', $this->map('image'));
    }

    public function test_mimes_maps_to_File(): void
    {
        self::assertSame('File', $this->map('mimes:png,jpg'));
    }

    // -------------------------------------------------------------------------
    // in: rule → union of string literals
    // -------------------------------------------------------------------------

    public function test_in_rule_produces_union_literals(): void
    {
        self::assertSame("'a' | 'b' | 'c'", $this->map('in:a,b,c'));
    }

    public function test_in_rule_single_value(): void
    {
        self::assertSame("'active'", $this->map('in:active'));
    }

    // -------------------------------------------------------------------------
    // not_in: rule → Exclude<base, union>
    // -------------------------------------------------------------------------

    public function test_not_in_string_values_produces_exclude_string(): void
    {
        self::assertSame("Exclude<string, 'a' | 'b' | 'c'>", $this->map('not_in:a,b,c'));
    }

    public function test_not_in_numeric_values_produces_exclude_number(): void
    {
        self::assertSame('Exclude<number, 1 | 2 | 3>', $this->map('not_in:1,2,3'));
    }

    public function test_not_in_single_string_value(): void
    {
        self::assertSame("Exclude<string, 'draft'>", $this->map('not_in:draft'));
    }

    public function test_not_in_single_numeric_value(): void
    {
        self::assertSame('Exclude<number, 0>', $this->map('not_in:0'));
    }

    public function test_not_in_mixed_values_produces_exclude_string(): void
    {
        // mixed numeric and non-numeric → treat as strings
        self::assertSame("Exclude<string, '1' | 'foo'>", $this->map('not_in:1,foo'));
    }

    // -------------------------------------------------------------------------
    // Sentinel for Closure / ConditionalRules
    // -------------------------------------------------------------------------

    public function test_unknown_sentinel_maps_to_unknown(): void
    {
        self::assertSame('unknown', $this->map('unknown'));
    }

    // -------------------------------------------------------------------------
    // Numeric size/comparison rules with literal arguments → number
    // -------------------------------------------------------------------------

    /** @dataProvider numericConstraintRules */
    public function test_numeric_constraint_with_literal_maps_to_number(string $rule): void
    {
        self::assertSame('number', $this->map($rule));
    }

    public static function numericConstraintRules(): array
    {
        return array_map(fn($r) => [$r], [
            'min:1',
            'min:0',
            'min:1.5',
            'max:255',
            'max:99.9',
            'size:8',
            'between:1,100',
            'between:0.5,99.5',
            'gt:0',
            'gt:0.5',
            'gte:1',
            'lt:10',
            'lte:100',
            'lte:99.9',
        ]);
    }

    /** @dataProvider numericConstraintFieldRefRules */
    public function test_numeric_constraint_with_field_ref_returns_null(string $rule): void
    {
        // When the argument is a field name, the rule is ambiguous (applies to strings too)
        self::assertNull($this->map($rule));
    }

    public static function numericConstraintFieldRefRules(): array
    {
        return array_map(fn($r) => [$r], [
            'gt:other_field',
            'gte:other_field',
            'lt:other_field',
            'lte:other_field',
            'min:other_field',
            'max:other_field',
            'between:1,other_field',
        ]);
    }

    // -------------------------------------------------------------------------
    // Rules that provide no type info
    // -------------------------------------------------------------------------

    public function test_required_returns_null(): void
    {
        self::assertNull($this->map('required'));
    }

    public function test_nullable_returns_null(): void
    {
        self::assertNull($this->map('nullable'));
    }
}
