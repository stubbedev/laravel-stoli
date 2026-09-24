<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class FileRouteBuilderNameFilterTest extends TestCase
{
    protected static function modules(): array
    {
        return [
            ['match' => '*', 'name' => 'store', 'names' => 'store.*', 'path' => 'resources/routes'],
            ['match' => '*', 'name' => 'admin-users', 'names' => ['admin.users.list', 'admin.users.update'], 'path' => 'resources/routes'],
            ['match' => '*', 'name' => 'nothing', 'names' => 'missing.*', 'path' => 'resources/routes'],
        ];
    }

    /**
     * @return list<string>
     */
    private function routeNames(string $module): array
    {
        $file = self::create(FileRouteBuilder::class)->files()
            ->firstOrFail(static fn (File $file): bool => $file->name() === $module);

        return array_values($file->routes()->map(static fn (Route $route): string => $route->name())->all());
    }

    public function test_a_wildcard_pattern_selects_routes_by_name_across_uri_prefixes(): void
    {
        self::assertEqualsCanonicalizing(
            ['store.products.list', 'store.cart.show', 'store.cart.add', 'store.cart.remove'],
            $this->routeNames('store'),
        );
    }

    public function test_a_list_of_names_selects_exactly_those_routes(): void
    {
        self::assertEqualsCanonicalizing(['admin.users.list', 'admin.users.update'], $this->routeNames('admin-users'));
    }

    public function test_a_pattern_matching_no_name_yields_an_empty_file(): void
    {
        self::assertSame([], $this->routeNames('nothing'));
    }

    public function test_modules_sharing_a_uri_match_each_keep_their_own_routes(): void
    {
        self::assertNotContains('admin.users.list', $this->routeNames('store'));
        self::assertNotContains('store.cart.show', $this->routeNames('admin-users'));
    }
}
