<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Compilers\ConstraintTypeMapper;
use StubbeDev\LaravelStoli\Compilers\JsonFileCompiler;
use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Support\ArrayList;

final class TypeScriptFileCompilerTest extends TestCase
{
    private TypeScriptFileCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new TypeScriptFileCompiler(new JsonFileCompiler(), new ConstraintTypeMapper());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function makeFile(string $name, array $routes): File
    {
        return new File($name, 'resources/routes', new ArrayList($routes));
    }

    private static function makeRoute(
        string  $name,
        string  $uri,
        array   $wheres = [],
        array   $methods = ['GET', 'HEAD'],
        ?string $stripPrefix = null,
        ?array  $dataRequestType = null,
        ?array  $dataResponseType = null,
    ): Route {
        return new Route(
            name:             $name,
            rootUrl:          'http://localhost',
            uri:              $uri,
            prefix:           null,
            absolute:         false,
            host:             null,
            wheres:           $wheres,
            methods:          $methods,
            stripPrefix:      $stripPrefix,
            dataRequestType:  $dataRequestType,
            dataResponseType: $dataResponseType,
        );
    }

    // -------------------------------------------------------------------------
    // Basic structure
    // -------------------------------------------------------------------------

    public function test_compile_produces_const_routes_block(): void
    {
        $file   = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('const routes =', $output);
        self::assertStringContainsString('export default routes', $output);
    }

    public function test_compile_produces_route_params_interface(): void
    {
        $file   = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export interface ApiRouteParams', $output);
        self::assertStringContainsString("'users.list':", $output);
    }

    public function test_compile_produces_route_name_type(): void
    {
        $file   = self::makeFile('api', [
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
        $file   = self::makeFile('api', [
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
        $file   = self::makeFile('api', [
            self::makeRoute('users.show', 'api/users/{id}'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('id:', $output);
        self::assertStringContainsString('string | number', $output);
    }

    public function test_optional_uri_param_is_not_required(): void
    {
        $file   = self::makeFile('api', [
            self::makeRoute('posts.show', 'api/posts/{slug?}'),
        ]);
        $output = $this->compiler->compile($file);

        // optional param gets '?' suffix
        self::assertStringContainsString('slug?:', $output);
    }

    public function test_whereNumber_constraint_produces_number_type(): void
    {
        $file   = self::makeFile('api', [
            self::makeRoute('users.show', 'api/users/{id}', wheres: ['id' => '[0-9]+']),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('id: number', $output);
    }

    public function test_whereIn_constraint_produces_union_literals(): void
    {
        $file   = self::makeFile('api', [
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
                dataRequestType: ['type' => 'StoreUserRequestData', 'file' => '/app/types/generated.ts', 'ambient' => false],
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
                dataRequestType: ['type' => 'StoreUserRequestData', 'file' => '/app/types/generated.ts', 'ambient' => false],
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
                dataRequestType: ['type' => 'UpdateUserRequestData', 'file' => '/app/types/generated.ts', 'ambient' => false],
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
                dataRequestType: ['type' => 'StoreUserRequestData', 'file' => '/app/resources/routes/types/generated.ts', 'ambient' => false],
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString("import type { StoreUserRequestData }", $output);
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
                dataRequestType: ['type' => 'App.Http.Data.StoreUserRequestData', 'file' => '/app/types/generated.ts', 'ambient' => true],
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
                dataRequestType: ['type' => 'App.Http.Data.StoreUserRequestData', 'file' => '/app/types/generated.ts', 'ambient' => true],
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
                dataResponseType: ['type' => 'ApiResponseData', 'file' => '/app/types/generated.ts', 'ambient' => false],
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export interface ApiRouteResponse', $output);
        self::assertStringContainsString("'users.store': ApiResponseData", $output);
    }

    public function test_response_interface_is_omitted_when_no_data_response_types(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users'),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringNotContainsString('export interface ApiRouteResponse', $output);
    }

    public function test_non_ambient_data_response_type_generates_import(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute(
                'users.store',
                'api/users',
                methods: ['POST'],
                dataResponseType: ['type' => 'ApiResponseData', 'file' => '/app/resources/routes/types/generated.ts', 'ambient' => false],
            ),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString("import type { ApiResponseData }", $output);
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
                dataResponseType: ['type' => 'App.Http.Data.ApiResponseData', 'file' => '/app/types/generated.ts', 'ambient' => true],
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
                dataResponseType: ['type' => 'App.Http.Data.ApiResponseData', 'file' => '/app/types/generated.ts', 'ambient' => true],
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
        $file   = self::makeFile('api', []);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('const routes =', $output);
        self::assertStringContainsString('export interface ApiRouteParams', $output);
    }

    // -------------------------------------------------------------------------
    // Extension
    // -------------------------------------------------------------------------

    public function test_extension_is_ts(): void
    {
        self::assertSame('ts', $this->compiler->extension());
    }
}
