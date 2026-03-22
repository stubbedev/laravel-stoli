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
        ?array  $params = null,
        ?array  $response = null,
        array   $methods = ['GET', 'HEAD'],
        ?string $stripPrefix = null,
    ): Route {
        return new Route(
            name:        $name,
            rootUrl:     'http://localhost',
            uri:         $uri,
            prefix:      null,
            absolute:    false,
            host:        null,
            params:      $params,
            response:    $response,
            wheres:      $wheres,
            methods:     $methods,
            stripPrefix: $stripPrefix,
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
    // FormRequest params
    // -------------------------------------------------------------------------

    public function test_form_request_params_appear_in_interface(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.create', 'api/users', params: [
                'name' => ['type' => 'string', 'required' => true],
                'age'  => ['type' => 'number', 'required' => false],
            ], methods: ['POST']),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('name: string', $output);
        self::assertStringContainsString('age?: number', $output);
    }

    public function test_form_request_overrides_uri_param_type(): void
    {
        // URI has {id} → string | number, but FormRequest says id is 'number'
        $file = self::makeFile('api', [
            self::makeRoute('users.show', 'api/users/{id}', params: [
                'id' => ['type' => 'number', 'required' => true],
            ]),
        ]);
        $output = $this->compiler->compile($file);

        // Should contain 'number' but NOT 'string | number'
        self::assertStringContainsString('id: number', $output);
        self::assertStringNotContainsString('string | number', $output);
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
    // Response interface
    // -------------------------------------------------------------------------

    public function test_response_interface_is_emitted_when_routes_have_response(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.show', 'api/users/{id}', response: [
                'wrap'       => null,
                'collection' => false,
                'shape'      => ['id' => 'number', 'name' => 'string'],
            ]),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('export interface ApiRouteResponse', $output);
        self::assertStringContainsString("'users.show':", $output);
        self::assertStringContainsString('id: number', $output);
        self::assertStringContainsString('name: string', $output);
    }

    public function test_collection_response_adds_array_suffix(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users', response: [
                'wrap'       => null,
                'collection' => true,
                'shape'      => ['id' => 'number', 'name' => 'string'],
            ]),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('[]', $output);
    }

    public function test_wrapped_response_includes_wrap_key(): void
    {
        $file = self::makeFile('api', [
            self::makeRoute('users.list', 'api/users', response: [
                'wrap'       => 'data',
                'collection' => true,
                'shape'      => ['id' => 'number'],
            ]),
        ]);
        $output = $this->compiler->compile($file);

        self::assertStringContainsString('{ data:', $output);
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
