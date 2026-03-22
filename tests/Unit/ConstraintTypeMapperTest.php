<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Compilers\ConstraintTypeMapper;

final class ConstraintTypeMapperTest extends TestCase
{
    private ConstraintTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ConstraintTypeMapper();
    }

    // -------------------------------------------------------------------------
    // Exact pattern matches (Laravel named helpers)
    // -------------------------------------------------------------------------

    public function test_whereNumber_maps_to_number(): void
    {
        self::assertSame('number', $this->mapper->map('[0-9]+'));
    }

    public function test_whereAlpha_maps_to_string(): void
    {
        self::assertSame('string', $this->mapper->map('[a-zA-Z]+'));
    }

    public function test_whereAlphaNumeric_maps_to_string(): void
    {
        self::assertSame('string', $this->mapper->map('[a-zA-Z0-9]+'));
    }

    public function test_whereUuid_maps_to_string(): void
    {
        self::assertSame('string', $this->mapper->map('[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}'));
    }

    public function test_whereUlid_maps_to_string(): void
    {
        self::assertSame('string', $this->mapper->map('[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}'));
    }

    // -------------------------------------------------------------------------
    // Literal alternation (whereIn) → union of string literals
    // -------------------------------------------------------------------------

    public function test_simple_alternation_produces_union_literals(): void
    {
        self::assertSame("'users' | 'groups'", $this->mapper->map('users|groups'));
    }

    public function test_single_value_alternation_produces_single_literal(): void
    {
        self::assertSame("'admin'", $this->mapper->map('admin'));
    }

    public function test_alternation_with_hyphens_and_underscores(): void
    {
        self::assertSame("'some-slug' | 'other_slug'", $this->mapper->map('some-slug|other_slug'));
    }

    // -------------------------------------------------------------------------
    // Generic digit-only patterns → number
    // -------------------------------------------------------------------------

    public function test_digit_shorthand_maps_to_number(): void
    {
        self::assertSame('number', $this->mapper->map('\d+'));
    }

    public function test_non_zero_digit_pattern_maps_to_number(): void
    {
        self::assertSame('number', $this->mapper->map('[1-9][0-9]*'));
    }

    // -------------------------------------------------------------------------
    // Fallback → string
    // -------------------------------------------------------------------------

    public function test_unknown_pattern_falls_back_to_string(): void
    {
        self::assertSame('string', $this->mapper->map('[a-z]{3,10}'));
    }

    public function test_malformed_regex_falls_back_to_string(): void
    {
        // Should not throw, just return string
        self::assertSame('string', $this->mapper->map('(unclosed'));
    }
}
