<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route as LaravelRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\Writers\FlatModuleWriter;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\SpatieDataTypeResolver;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\UserController;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * The types the fixture controller takes and responds with, against what the real
 * typescript-transformer generates for the fixture Data classes.
 */
final class SpatieDataTypeResolverTest extends TestCase
{
    private const DATA = 'StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data';

    private static function directory(): string
    {
        return sys_get_temp_dir().'/stoli-resolver';
    }

    protected static function modules(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::useTransformer(self::directory());
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::directory());

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $array
     * @return list<string>
     */
    private static function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }

    private static function route(string $method): LaravelRoute
    {
        return new LaravelRoute('GET', '/stub', ['uses' => UserController::class.'@'.$method]);
    }

    private function response(string $method): ?DataType
    {
        return self::create(SpatieDataTypeResolver::class)->response(self::route($method));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function responses(): iterable
    {
        $user = self::DATA.'.UserData';
        $wrapped = self::DATA.'.ApiResponseData';

        yield 'Data class' => ['show', $user];
        yield 'DataCollection' => ['index', "{$user}[]"];
        yield 'list array' => ['list', "{$user}[]"];
        yield 'Collection keyed by int' => ['collection', "{$user}[]"];
        yield 'array keyed by string' => ['keyed', "Record<string, {$user}>"];
        yield 'array shape' => ['shape', "{ user: {$user}; total?: number; kind: 'a' | 'b' }"];
        yield 'nullable return type' => ['maybe', "{$user} | null"];
        yield 'void' => ['nothing', 'void'];
        yield 'generic Data' => ['wrapped', "{$wrapped}<{$user}>"];
        yield 'generic Data of null' => ['wrappedNull', "{$wrapped}<null>"];
        yield 'generic Data of an enum' => ['wrappedEnum', "{$wrapped}<".self::DATA.'.Status>'];
        yield 'nested generic argument' => ['wrappedNested', "{$wrapped}<{$user}[] | null>"];
        yield 'generic Data without its argument' => ['wrappedUntagged', "{$wrapped}<unknown>"];
        yield 'PaginatedDataCollection' => ['paginated', "Paginated<{$user}>"];
        yield 'CursorPaginatedDataCollection' => ['cursor', "CursorPaginated<{$user}>"];
        yield 'class the transformer does not know' => ['untransformed', null];
    }

    #[DataProvider('responses')]
    public function test_it_resolves_the_response_type(string $method, ?string $expected): void
    {
        self::assertSame($expected, $this->response($method)?->type);
    }

    public function test_the_request_type_is_the_first_data_parameter(): void
    {
        $request = self::create(SpatieDataTypeResolver::class)->request(self::route('update'));

        self::assertSame('StoreUserDataInput', $request?->type);
        self::assertSame(
            ['StoreUserDataInput' => 'export interface StoreUserDataInput extends DataInput<'.self::DATA.".StoreUserData, { name: 'name'; admin: 'admin' }, 'admin', {}> {}"],
            $request->declarations,
        );
        self::assertSame([DataType::RUNTIME => ['DataInput']], $request->imports);
        self::assertNull(self::create(SpatieDataTypeResolver::class)->request(self::route('show')));
    }

    public function test_a_request_is_typed_as_it_is_taken_in(): void
    {
        self::assertSame(
            ['ProfileDataInput' => 'export interface ProfileDataInput extends DataInput<'.self::DATA.".ProfileData, { displayName: 'display_name'; mail: 'email'; bio: 'bio'; website: 'website'; public: 'public'; password: 'password' }, 'bio' | 'website' | 'public' | 'password', {}> {}"],
            self::create(SpatieDataTypeResolver::class)->request(self::route('profile'))?->declarations,
        );
    }

    public function test_nested_data_is_taken_in_as_its_own_input_type_and_brings_its_declaration(): void
    {
        $request = self::create(SpatieDataTypeResolver::class)->request(self::route('order'));

        self::assertSame('OrderDataInput', $request?->type);
        self::assertSame(['AddressDataInput', 'LineDataInput', 'OrderDataInput'], self::sortedKeys($request->declarations));
        self::assertStringContainsString(
            "{ shipping: AddressDataInput; billing: AddressDataInput | null; lines: LineDataInput[]; state: 'draft' | 'placed'; status: 'active' | 'archived'",
            $request->declarations['OrderDataInput'],
        );
        self::assertStringContainsString("{ password_confirmation: ".self::DATA.".OrderData['password'] }> {}", $request->declarations['OrderDataInput']);
        self::assertStringNotContainsString('internal', $request->declarations['OrderDataInput']);
    }

    public function test_a_cycle_ends_at_the_name_of_the_input_type(): void
    {
        $request = self::create(SpatieDataTypeResolver::class)->request(self::route('category'));

        self::assertNotNull($request);
        self::assertSame(['CategoryDataInput'], array_keys($request->declarations));
        self::assertStringContainsString('{ children: CategoryDataInput[]; parent: CategoryDataInput | null }', $request->declarations['CategoryDataInput']);
    }

    public function test_a_form_request_takes_in_what_its_rules_say_its_parents_included(): void
    {
        $request = self::create(SpatieDataTypeResolver::class)->request(self::route('article'));

        self::assertSame('StoreArticleRequestInput', $request?->type);
        self::assertStringStartsWith("export type StoreArticleRequestInput = { locale: 'en' | 'da'; title: string;", $request->declarations['StoreArticleRequestInput']);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function statuses(): iterable
    {
        yield 'Data to a GET' => ['show', ['get'], '200'];
        yield 'Data to a POST' => ['store', ['post'], '201'];
        yield 'Data to a GET or a POST' => ['store', ['get', 'post'], '200 | 201'];
        yield 'DataCollection' => ['index', ['get'], '200'];
        yield 'paginated collection' => ['paginated', ['post'], '201'];
        yield 'plain array' => ['list', ['post'], '200'];
        yield 'void' => ['nothing', ['post'], '200'];
        yield 'Data sending its own response' => ['selfResponding', ['get'], 'number'];
        yield 'nothing resolvable' => ['untransformed', ['get'], 'number'];
    }

    /**
     * @param  list<string>  $methods
     */
    #[DataProvider('statuses')]
    public function test_the_status_a_route_succeeds_with(string $method, array $methods, string $expected): void
    {
        self::assertSame($expected, self::create(SpatieDataTypeResolver::class)->status(self::route($method), $methods));
    }

    public function test_route_parameters_are_typed_by_the_signature(): void
    {
        self::assertSame(
            ['status' => "'active' | 'archived'", 'page' => 'number'],
            self::create(SpatieDataTypeResolver::class)->parameters(self::route('byStatus')),
        );
    }

    public function test_a_bound_model_takes_the_type_of_its_key(): void
    {
        $route = new LaravelRoute('GET', '/posts/{post}/{article}/{byTitle:title}', ['uses' => UserController::class.'@bound']);

        self::assertSame(
            ['post' => 'number', 'article' => 'string', 'byTitle' => 'string'],
            self::create(SpatieDataTypeResolver::class)->parameters($route),
        );
    }

    public function test_a_paginated_response_imports_its_envelope_from_the_route_service(): void
    {
        self::assertSame([DataType::RUNTIME => ['Paginated']], $this->response('paginated')?->imports);
    }

    public function test_a_module_export_is_imported_from_the_file_the_writer_writes_it_to(): void
    {
        self::useTransformer(self::directory(), new FlatModuleWriter('types.ts'));

        $response = $this->response('wrapped');

        self::assertSame('ApiResponseData<UserData>', $response?->type);
        self::assertSame([self::directory().'/types.ts' => ['ApiResponseData', 'UserData']], $response->imports);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrappedResponses(): iterable
    {
        $user = self::DATA.'.UserData';

        yield 'Data class under the config key' => ['show', "{ items: {$user} }"];
        yield 'defaultWrap() over the config key' => ['wrappedByClass', '{ user: '.self::DATA.'.WrappedUserData }'];
        yield 'own toResponse(), never wrapped' => ['selfResponding', self::DATA.'.SelfRespondingUserData'];
        yield 'DataCollection under the config key' => ['index', "{ items: {$user}[] }"];
        yield 'plain array, never wrapped' => ['list', "{$user}[]"];
        yield 'paginated items under the config key' => ['paginated', "Paginated<{$user}, 'items'>"];
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

    public function test_without_a_transformer_nothing_resolves(): void
    {
        app()->offsetUnset(TypeScriptTransformerConfig::class);
        self::forgetResolved();

        self::assertNull($this->response('show'));
    }
}
