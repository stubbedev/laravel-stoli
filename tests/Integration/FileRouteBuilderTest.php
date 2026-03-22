<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class FileRouteBuilderTest extends TestCase
{
    protected static function modules(): array
    {
        return [
            ['match' => '*', 'name' => 'api', 'path' => 'resources/routes'],
        ];
    }

    // -------------------------------------------------------------------------
    // Basic file building
    // -------------------------------------------------------------------------

    public function test_returns_one_file_per_module(): void
    {
        $builder = static::create(FileRouteBuilder::class);
        $files   = $builder->files();

        self::assertSame(1, $files->count());
    }

    public function test_file_contains_named_routes(): void
    {
        $builder = static::create(FileRouteBuilder::class);

        /** @var File $file */
        $file = $builder->files()->values()[0];

        // TestCase::defineRoutes registers store.* and admin.* routes
        self::assertGreaterThan(0, $file->routes()->count());
    }

    public function test_file_name_matches_module_name(): void
    {
        $builder = static::create(FileRouteBuilder::class);

        /** @var File $file */
        $file = $builder->files()->values()[0];

        self::assertSame('api', $file->name());
    }

    public function test_route_names_are_preserved(): void
    {
        $builder = static::create(FileRouteBuilder::class);

        /** @var File $file */
        $file  = $builder->files()->values()[0];
        $names = $file->routes()->map(fn($r) => $r->name())->values();

        self::assertContains('store.products.list', $names);
        self::assertContains('admin.users.list', $names);
    }
}


