<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route as LaravelRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\SpatieDataTypeResolver;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\UserController;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class SpatieDataTypeResolverCollectionsTest extends TestCase
{
    private const DATA = 'StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data';

    private static function directory(): string
    {
        return sys_get_temp_dir().'/stoli-resolver-collections';
    }

    protected static function modules(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new Filesystem)->ensureDirectoryExists(self::directory());
        (new Filesystem)->copy(dirname(__DIR__).'/typescript/types.d.ts', self::directory().'/index.d.ts');

        self::useTransformerOutputDirectory(self::directory());
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::directory());

        parent::tearDown();
    }

    private function response(string $method): ?DataType
    {
        $route = new LaravelRoute('GET', '/stub', ['uses' => UserController::class.'@'.$method]);

        return self::create(SpatieDataTypeResolver::class)->resolve($route)['response'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function responses(): iterable
    {
        yield 'Data class' => ['show', self::DATA.'.UserData'];
        yield 'DataCollection' => ['index', self::DATA.'.UserData[]'];
        yield 'list array' => ['list', self::DATA.'.UserData[]'];
        yield 'generic Data' => ['wrapped', self::DATA.'.ApiResponseData<'.self::DATA.'.UserData>'];
        yield 'PaginatedDataCollection' => ['paginated', 'Paginated<'.self::DATA.'.UserData>'];
        yield 'CursorPaginatedDataCollection' => ['cursor', 'CursorPaginated<'.self::DATA.'.UserData>'];
    }

    #[DataProvider('responses')]
    public function test_it_resolves_the_response_type(string $method, string $expected): void
    {
        self::assertSame($expected, $this->response($method)?->type);
    }

    public function test_a_paginated_response_imports_its_envelope_from_the_route_service_types(): void
    {
        self::assertSame(
            [self::directory().'/stoli.d.ts' => ['Paginated']],
            $this->response('paginated')?->imports,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrappedResponses(): iterable
    {
        yield 'Data class under the config key' => ['show', '{ items: '.self::DATA.'.UserData }'];
        yield 'defaultWrap() over the config key' => ['wrappedByClass', '{ user: '.self::DATA.'.WrappedUserData }'];
        yield 'own toResponse(), never wrapped' => ['selfResponding', self::DATA.'.SelfRespondingUserData'];
        yield 'DataCollection under the config key' => ['index', '{ items: '.self::DATA.'.UserData[] }'];
        yield 'plain array, never wrapped' => ['list', self::DATA.'.UserData[]'];
        yield 'paginated items under the config key' => ['paginated', 'Paginated<'.self::DATA.".UserData, 'items'>"];
    }

    #[DataProvider('wrappedResponses')]
    public function test_it_wraps_the_response_like_laravel_data(string $method, string $expected): void
    {
        config(['data.wrap' => 'items']);

        self::assertSame($expected, $this->response($method)?->type);
    }

    public function test_without_a_config_key_only_defaultwrap_wraps(): void
    {
        self::assertSame('{ user: '.self::DATA.'.WrappedUserData }', $this->response('wrappedByClass')?->type);
        self::assertSame(self::DATA.'.UserData', $this->response('show')?->type);
    }
}
