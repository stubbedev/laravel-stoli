<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Items\Module;

final class ModuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function uris(): iterable
    {
        yield 'wildcard, any uri' => ['*', 'api/users', true];
        yield 'wildcard, empty uri' => ['*', '', true];
        yield 'root path, any uri' => ['/', 'api/users', true];
        yield 'the path itself' => ['/api', 'api', true];
        yield 'under the path' => ['api/users', 'api/users/1', true];
        yield 'trailing slash' => ['api/', 'api/users', true];
        yield 'another path' => ['api/users', 'api/orders', false];
        yield 'only whole segments' => ['/api', 'apidocs', false];
        yield 'only whole segments, deeper' => ['api/users', 'api/users-archive', false];
    }

    #[DataProvider('uris')]
    public function test_it_takes_the_routes_at_or_under_its_path(string $match, string $uri, bool $expected): void
    {
        self::assertSame($expected, (new Module('api', match: $match))->matches($uri, 'any'));
    }

    public function test_a_names_filter_takes_only_the_names_it_matches(): void
    {
        $module = new Module('api', names: ['store.*', 'admin.users.list']);

        self::assertTrue($module->matches('x', 'store.cart.show'));
        self::assertTrue($module->matches('x', 'admin.users.list'));
        self::assertFalse($module->matches('x', 'admin.users.update'));
    }

    public function test_the_strip_prefix_comes_off_the_route_name(): void
    {
        $module = new Module('store', stripPrefix: 'store.');

        self::assertSame('products.list', $module->routeName('store.products.list'));
        self::assertSame('admin.products.list', $module->routeName('admin.products.list'));
    }

    public function test_the_prefix_and_uri_are_joined_without_stray_slashes(): void
    {
        self::assertSame('api/home', (new Module('api', prefix: '/api/'))->uri('/home/'));
        self::assertSame('home', (new Module('api'))->uri('home'));
    }

    /**
     * @return iterable<string, array{string, bool, string|null, string|null}>
     */
    public static function hosts(): iterable
    {
        yield 'the root url without a domain' => ['https://app.test/', true, null, 'https://app.test'];
        yield 'relative without a domain' => ['https://app.test', false, null, null];
        yield 'relative without a root url' => ['', true, null, null];
        yield 'a domain borrows the root url scheme' => ['https://app.test', true, 'api.app.test', 'https://api.app.test'];
        yield 'a domain is protocol-relative without a scheme' => ['', true, 'api.app.test', '//api.app.test'];
        yield 'a domain with a scheme is kept' => ['https://app.test', true, 'http://api.app.test/', 'http://api.app.test'];
        yield 'a domain is kept on a relative module' => ['https://app.test', false, '{account}.app.test', 'https://{account}.app.test'];
    }

    #[DataProvider('hosts')]
    public function test_the_host_a_route_is_built_on(string $rootUrl, bool $absolute, ?string $domain, ?string $expected): void
    {
        self::assertSame($expected, (new Module('api', rootUrl: $rootUrl, absolute: $absolute))->host($domain));
    }
}
