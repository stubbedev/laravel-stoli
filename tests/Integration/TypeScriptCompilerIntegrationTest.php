<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use StubbeDev\LaravelStoli\Compilers\ConstraintTypeMapper;
use StubbeDev\LaravelStoli\Compilers\JsonFileCompiler;
use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * End-to-end test: build real route files from the test route fixtures and
 * assert that the generated TypeScript contains the expected types.
 */
final class TypeScriptCompilerIntegrationTest extends TestCase
{
    protected static function modules(): array
    {
        return [
            ['match' => '*', 'name' => 'api', 'path' => 'resources/routes'],
        ];
    }

    private function compile(): string
    {
        $builder  = static::create(FileRouteBuilder::class);
        $compiler = new TypeScriptFileCompiler(new JsonFileCompiler(), new ConstraintTypeMapper());

        /** @var File $file */
        $file = $builder->files()->values()[0];

        return $compiler->compile($file);
    }

    public function test_generated_output_contains_all_route_names(): void
    {
        $output = $this->compile();

        self::assertStringContainsString("'store.products.list'", $output);
        self::assertStringContainsString("'store.cart.show'", $output);
        self::assertStringContainsString("'store.cart.add'", $output);
        self::assertStringContainsString("'store.cart.remove'", $output);
        self::assertStringContainsString("'admin.products.create'", $output);
        self::assertStringContainsString("'admin.products.show'", $output);
        self::assertStringContainsString("'admin.users.list'", $output);
    }

    public function test_generated_output_has_route_params_interface(): void
    {
        $output = $this->compile();

        self::assertStringContainsString('export interface ApiRouteParams', $output);
    }

    public function test_parameterised_routes_include_param_in_interface(): void
    {
        $output = $this->compile();

        // store.cart.add and store.cart.remove have {product_id} URI param
        self::assertStringContainsString('product_id', $output);
    }

    public function test_list_route_has_empty_params_record(): void
    {
        $output = $this->compile();

        // store.products.list has no params
        self::assertStringContainsString('Record<string, unknown>', $output);
    }

    public function test_patch_route_is_in_patch_type(): void
    {
        $output = $this->compile();

        self::assertStringContainsString('export type ApiPatchRouteName', $output);
        self::assertStringContainsString("'store.cart.add'", $output);
    }

    public function test_delete_route_is_in_delete_type(): void
    {
        $output = $this->compile();

        self::assertStringContainsString('export type ApiDeleteRouteName', $output);
        self::assertStringContainsString("'store.cart.remove'", $output);
    }

    public function test_output_is_valid_typescript_structure(): void
    {
        $output = $this->compile();

        // Must start with const routes or an import
        self::assertMatchesRegularExpression('/^(import|const)/', ltrim($output));

        // Must export default routes
        self::assertStringContainsString('export default routes', $output);
    }
}
