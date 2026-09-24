<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Compilers\ConstraintTypeMapper;
use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use Illuminate\Support\Collection;

final class TypeScriptFileCompilerTest extends TestCase
{
    private TypeScriptFileCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new TypeScriptFileCompiler(new ConstraintTypeMapper);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<Route>  $routes
     */
    private static function makeFile(string $name, array $routes): File
    {
        return new File($name, 'resources/routes', new Collection($routes));
    }

    /**
     * @param  array<string, string>  $wheres
     * @param  list<string>  $methods
     */
    private static function makeRoute(
        string $name,
        string $uri,
        array $wheres = [],
        array $methods = ['GET', 'HEAD'],
        ?string $stripPrefix = null,
        ?DataType $dataRequestType = null,
        ?DataType $dataResponseType = null,
    ): Route {
        return new Route(
            name: $name,
            rootUrl: 'http://localhost',
            uri: $uri,
            prefix: null,
            absolute: false,
            host: null,
            wheres: $wheres,
            methods: $methods,
            stripPrefix: $stripPrefix,
            dataRequestType: $dataRequestType,
            dataResponseType: $dataResponseType,
        );
    }

    // -------------------------------------------------------------------------
    // Basic structure
    // -------------------------------------------------------------------------

    public function test_compile_produces_const_routes_block(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('const routes =', $output);
        self::assertStringContainsString('export default routes', $output);
    }

    public function test_compile_produces_route_params_interface(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export interface ApiRouteParams', $output);
        self::assertStringContainsString("'users.list':", $output);
    }

    public function test_compile_produces_route_name_type(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export type ApiRouteName = keyof ApiRouteParams', $output);
    }

    public function test_compile_produces_http_method_types(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users', methods: ['GET', 'HEAD']),
            self::makeRoute('users.create', 'api/users', methods: ['POST']),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export type ApiGetRouteName', $output);
        self::assertStringContainsString("'users.list'", $output);
        self::assertStringContainsString('export type ApiPostRouteName', $output);
        self::assertStringContainsString("'users.create'", $output);
    }

    public function test_method_type_is_never_when_no_routes_for_method(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users', methods: ['GET', 'HEAD']),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export type ApiDeleteRouteName = never', $output);
    }

    // -------------------------------------------------------------------------
    // URI parameter extraction
    // -------------------------------------------------------------------------

    public function test_uri_params_appear_in_params_interface(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.show', 'api/users/{id}'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('id:', $output);
        self::assertStringContainsString('string | number', $output);
    }

    public function test_optional_uri_param_is_not_required(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('posts.show', 'api/posts/{slug?}'),
        ]);
        $output = $this->compiler->compile($file);

        // optional param gets '?' suffix
        self::assertStringContainsString('slug?:', $output);
    }

    public function test_where_number_constraint_produces_number_type(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.show', 'api/users/{id}', wheres: ['id' => '[0-9]+']),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('id: number', $output);
    }

    public function test_where_in_constraint_produces_union_literals(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('items.show', 'api/items/{type}', wheres: ['type' => 'foo|bar']),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString("type: 'foo' | 'bar'", $output);
    }

    // -------------------------------------------------------------------------
    // Spatie Data request type
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Spatie Data request type — non-ambient (export type)
    // -------------------------------------------------------------------------

    public function test_data_request_type_appears_in_params_interface(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.create',
                'api/users',
                methods: ['POST'],
                dataRequestType: new DataType('StoreUserRequestData', '/app/types/generated.ts', ['StoreUserRequestData']),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('StoreUserRequestData', $output);
    }

    public function test_data_request_type_without_uri_params_emits_data_type_only(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.create',
                'api/users',
                methods: ['POST'],
                dataRequestType: new DataType('StoreUserRequestData', '/app/types/generated.ts', ['StoreUserRequestData']),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString("'users.create': StoreUserRequestData", $output);
        self::assertStringNotContainsString('&', $output);
    }

    public function test_data_request_type_with_uri_params_emits_intersection(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.update',
                'api/users/{userId}',
                methods: ['PUT'],
                dataRequestType: new DataType('UpdateUserRequestData', '/app/types/generated.ts', ['UpdateUserRequestData']),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('userId', $output);
        self::assertStringContainsString('& UpdateUserRequestData', $output);
    }

    public function test_non_ambient_data_request_type_generates_import(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.create',
                'api/users',
                methods: ['POST'],
                dataRequestType: new DataType('StoreUserRequestData', '/app/resources/routes/types/generated.ts', ['StoreUserRequestData']),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('import type { StoreUserRequestData }', $output);
    }

    // -------------------------------------------------------------------------
    // Spatie Data request type — ambient (declare namespace)
    // -------------------------------------------------------------------------

    public function test_ambient_data_request_type_uses_dotted_namespace_path(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.create',
                'api/users',
                methods: ['POST'],
                dataRequestType: new DataType('App.Http.Data.StoreUserRequestData', '/app/types/generated.ts'),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('App.Http.Data.StoreUserRequestData', $output);
    }

    public function test_ambient_data_request_type_does_not_generate_import(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.create',
                'api/users',
                methods: ['POST'],
                dataRequestType: new DataType('App.Http.Data.StoreUserRequestData', '/app/types/generated.ts'),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringNotContainsString('import type', $output);
    }

    // -------------------------------------------------------------------------
    // Spatie Data response type — non-ambient (export type)
    // -------------------------------------------------------------------------

    public function test_data_response_type_appears_in_response_interface(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.store',
                'api/users',
                methods: ['POST'],
                dataResponseType: new DataType('ApiResponseData', '/app/types/generated.ts', ['ApiResponseData']),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export interface ApiRouteResponse', $output);
        self::assertStringContainsString("'users.store': ApiResponseData", $output);
    }

    public function test_response_interface_is_included_when_no_data_response_types(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export interface ApiRouteResponse', $output);
    }

    public function test_non_ambient_data_response_type_generates_import(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.store',
                'api/users',
                methods: ['POST'],
                dataResponseType: new DataType('ApiResponseData', '/app/resources/routes/types/generated.ts', ['ApiResponseData']),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('import type { ApiResponseData }', $output);
    }

    // -------------------------------------------------------------------------
    // Spatie Data response type — ambient (declare namespace)
    // -------------------------------------------------------------------------

    public function test_ambient_data_response_type_uses_dotted_namespace_path(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.store',
                'api/users',
                methods: ['POST'],
                dataResponseType: new DataType('App.Http.Data.ApiResponseData', '/app/types/generated.ts'),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString("'users.store': App.Http.Data.ApiResponseData", $output);
    }

    public function test_ambient_data_response_type_does_not_generate_import(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.store',
                'api/users',
                methods: ['POST'],
                dataResponseType: new DataType('App.Http.Data.ApiResponseData', '/app/types/generated.ts'),
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringNotContainsString('import type', $output);
    }

    // -------------------------------------------------------------------------
    // stripPrefix
    // -------------------------------------------------------------------------

    public function test_strip_prefix_removes_prefix_from_route_name(): void
    {
        $file = self::makeFile('store', [
            self::makeRoute('store.products.list', 'api/store/products', stripPrefix: 'store.'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString("'products.list'", $output);
        self::assertStringNotContainsString("'store.products.list'", $output);
    }

    // -------------------------------------------------------------------------
    // Empty route set
    // -------------------------------------------------------------------------

    public function test_empty_file_compiles_without_error(): void
    {
        $file = self::makeFile('api', []);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('const routes =', $output);
        self::assertStringContainsString('export interface ApiRouteParams', $output);
    }

    // -------------------------------------------------------------------------
    // Regressions
    // -------------------------------------------------------------------------

    public function test_imports_resolve_from_an_absolute_module_path(): void
    {
        // The transformer output directory is usually absolute.
        $file = new File('api', '/app/resources/routes', new Collection([
            self::makeRoute(
                'users.create',
                'api/users',
                methods: ['POST'],
                dataRequestType: new DataType('StoreUserRequestData', '/app/resources/types/index.d.ts', ['StoreUserRequestData']),
            ),
        ]));

        self::assertStringContainsString(
            "import type { StoreUserRequestData } from '../types/index';",
            $this->compiler->compile($file),
        );
    }

    public function test_a_generic_response_imports_each_type_by_name(): void
    {
        $response = (new DataType('ApiResponseData', '/app/types/generated.ts', ['ApiResponseData']))
            ->withArgument('UserData', ['UserData']);

        $output = $this->compiler->compile(self::makeFile('api', [
            self::makeRoute('users.show', 'api/users/{id}', dataResponseType: $response),
        ]));

        self::assertStringContainsString('import type { ApiResponseData, UserData } from', $output);
        self::assertStringContainsString("'users.show': ApiResponseData<UserData>;", $output);
    }

    public function test_a_numeric_route_name_keeps_its_name(): void
    {
        $output = $this->compiler->compile(self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
            self::makeRoute('404', 'missing'),
        ]));

        self::assertStringContainsString("'404': {", $output);
        self::assertStringContainsString("'404': Record<string, unknown>;", $output);
    }

    public function test_a_file_without_routes_compiles_to_an_empty_object(): void
    {
        self::assertStringContainsString('const routes = {} as const;', $this->compiler->compile(self::makeFile('api', [])));
    }

    public function test_domain_parameters_are_part_of_the_params_type(): void
    {
        $route = new Route(
            name: 'tenant.home',
            rootUrl: 'https://app.test',
            uri: 'home',
            prefix: null,
            absolute: true,
            host: '{account}.app.test',
            wheres: ['account' => '[a-z]+'],
        );

        $output = $this->compiler->compile(self::makeFile('api', [$route]));

        self::assertStringContainsString("host: 'https://{account}.app.test',", $output);
        self::assertStringContainsString("'tenant.home': { account: string; [key: string]: unknown };", $output);
    }
}
