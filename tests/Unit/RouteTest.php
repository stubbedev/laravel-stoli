<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Items\Route;

final class RouteTest extends TestCase
{
    private static function route(string $rootUrl, ?string $host, bool $absolute = true): Route
    {
        return new Route(
            name: 'home',
            rootUrl: $rootUrl,
            uri: '/home/',
            prefix: '/api/',
            absolute: $absolute,
            host: $host,
        );
    }

    public function test_the_root_url_is_the_host_of_a_route_without_a_domain(): void
    {
        self::assertSame('https://app.test', self::route('https://app.test/', null)->host());
    }

    public function test_a_route_domain_borrows_the_root_url_scheme(): void
    {
        self::assertSame('https://api.app.test', self::route('https://app.test', 'api.app.test')->host());
    }

    public function test_a_route_domain_is_protocol_relative_without_a_root_url_scheme(): void
    {
        self::assertSame('//api.app.test', self::route('', 'api.app.test')->host());
    }

    public function test_a_route_domain_with_a_scheme_is_kept(): void
    {
        self::assertSame('http://api.app.test', self::route('https://app.test', 'http://api.app.test/')->host());
    }

    public function test_relative_routes_have_no_host(): void
    {
        self::assertNull(self::route('https://app.test', 'api.app.test', absolute: false)->host());
    }

    public function test_the_prefix_and_uri_are_joined_without_stray_slashes(): void
    {
        self::assertSame('api/home', self::route('https://app.test', null)->uri());
    }
}
