<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StubbeDev\LaravelStoli\ClassNameResolver;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data\ApiResponseData;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data\StoreUserData;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data\UserData;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\UserController;

final class ClassNameResolverTest extends TestCase
{
    /**
     * UserController imports its Data classes as
     * `use ...\Data\{ApiResponseData, StoreUserData, UserData as User};`.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function names(): iterable
    {
        yield 'grouped import' => ['ApiResponseData', ApiResponseData::class];
        yield 'another in the group' => ['StoreUserData', StoreUserData::class];
        yield 'aliased in a group' => ['User', UserData::class];
        yield 'alias is case-insensitive' => ['user', UserData::class];
        yield 'the aliased name itself is not imported' => ['UserData', null];
        yield 'fully qualified' => ['\\'.UserData::class, UserData::class];
        yield 'relative to the namespace' => ['Data\\UserData', UserData::class];
        yield 'unknown' => ['Nope', null];
    }

    #[DataProvider('names')]
    public function test_it_resolves_names_as_php_would_in_the_declaring_file(string $name, ?string $expected): void
    {
        self::assertSame($expected, (new ClassNameResolver)->resolve($name, new ReflectionClass(UserController::class)));
    }

    public function test_a_qualified_name_resolves_through_an_imported_namespace(): void
    {
        // This file imports ...\Fixtures\TypeScript\Data\UserData, not the namespace, so
        // Data\UserData is relative to this file's own namespace and does not exist.
        self::assertNull((new ClassNameResolver)->resolve('Data\\UserData', new ReflectionClass(self::class)));

        // An import of the class itself resolves by its alias.
        self::assertSame(UserData::class, (new ClassNameResolver)->resolve('UserData', new ReflectionClass(self::class)));
    }
}
