<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Matchers\StartsWithRouteMatcher;

final class StartsWithRouteMatcherTest extends TestCase
{
    private StartsWithRouteMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new StartsWithRouteMatcher;
    }

    public function test_wildcard_matches_any_uri(): void
    {
        self::assertTrue($this->matcher->matches('api/users', '*'));
        self::assertTrue($this->matcher->matches('anything', '*'));
        self::assertTrue($this->matcher->matches('', '*'));
    }

    public function test_exact_prefix_matches(): void
    {
        self::assertTrue($this->matcher->matches('api/users', 'api/users'));
        self::assertTrue($this->matcher->matches('api/users/1', 'api/users'));
    }

    public function test_non_matching_prefix_returns_false(): void
    {
        self::assertFalse($this->matcher->matches('api/orders', 'api/users'));
        self::assertFalse($this->matcher->matches('admin', 'api'));
    }

    public function test_leading_slash_stripped_from_pattern(): void
    {
        // Module constructor strips leading '/' but matcher receives the already-stripped value.
        // Test raw matcher behaviour: the match is a startsWith check.
        self::assertTrue($this->matcher->matches('api/users', 'api'));
        self::assertFalse($this->matcher->matches('other/path', 'api'));
    }
}
