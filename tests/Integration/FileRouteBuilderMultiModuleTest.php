<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class FileRouteBuilderMultiModuleTest extends TestCase
{
    protected static function modules(): array
    {
        return [
            ['match' => 'api/store', 'name' => 'store', 'path' => 'resources/routes'],
            ['match' => 'api/admin', 'name' => 'admin', 'path' => 'resources/routes'],
        ];
    }

    public function test_returns_two_files_for_two_modules(): void
    {
        $builder = self::create(FileRouteBuilder::class);
        $files = $builder->files();

        self::assertSame(2, $files->count());
    }

    public function test_store_module_only_contains_store_routes(): void
    {
        $builder = self::create(FileRouteBuilder::class);
        $files = $builder->files();

        $storeFile = $files->filter(fn (File $f) => $f->name() === 'store')->values()[0] ?? null;

        self::assertNotNull($storeFile);

        $names = $storeFile->routes()->map(fn ($r) => $r->name())->values();

        foreach ($names as $name) {
            self::assertStringStartsWith('store.', $name);
        }
    }

    public function test_admin_module_only_contains_admin_routes(): void
    {
        $builder = self::create(FileRouteBuilder::class);
        $files = $builder->files();

        $adminFile = $files->filter(fn (File $f) => $f->name() === 'admin')->values()[0] ?? null;

        self::assertNotNull($adminFile);

        $names = $adminFile->routes()->map(fn ($r) => $r->name())->values();

        foreach ($names as $name) {
            self::assertStringStartsWith('admin.', $name);
        }
    }
}
