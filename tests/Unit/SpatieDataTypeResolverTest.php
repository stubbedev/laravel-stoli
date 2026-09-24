<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use Illuminate\Routing\Route as LaravelRoute;
use LogicException;
use Spatie\LaravelData\Data;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\SpatieDataTypeResolver;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * @template TData
 */
class ApiResponseData extends Data
{
}

class InnerData extends Data
{
}

enum ResolverStatus: string
{
    case Active = 'active';
}

class ResolverStubController
{
    /**
     * @return ApiResponseData<null>
     */
    public function nullGeneric(): ApiResponseData
    {
        throw new LogicException('not called');
    }

    /**
     * @return ApiResponseData<InnerData>
     */
    public function classGeneric(): ApiResponseData
    {
        throw new LogicException('not called');
    }

    /**
     * @return ApiResponseData<ResolverStatus>
     */
    public function enumGeneric(): ApiResponseData
    {
        throw new LogicException('not called');
    }
}

final class SpatieDataTypeResolverTest extends TestCase
{
    protected static function modules(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $directory = sys_get_temp_dir() . '/stoli-resolver-' . getmypid();

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents(
            $directory . '/index.d.ts',
            "export type ApiResponseData<TData> = { data: TData };\nexport type InnerData = { id: number };\nexport type ResolverStatus = 'active';\n",
        );

        self::useTransformerOutputDirectory($directory);
    }

    public function test_it_renders_a_non_class_generic_argument_literally(): void
    {
        self::assertSame('ApiResponseData<null>', $this->resolveResponseType('nullGeneric'));
    }

    public function test_it_resolves_a_data_class_generic_argument(): void
    {
        self::assertSame('ApiResponseData<InnerData>', $this->resolveResponseType('classGeneric'));
    }

    public function test_a_transformed_enum_is_a_generic_argument_too(): void
    {
        self::assertSame('ApiResponseData<ResolverStatus>', $this->resolveResponseType('enumGeneric'));
    }

    public function test_a_generic_module_export_imports_both_types(): void
    {
        self::assertSame([['ApiResponseData', 'InnerData']], array_values($this->resolveResponse('classGeneric')->imports ?? []));
    }

    public function test_an_ambient_type_is_found_at_its_namespace_path(): void
    {
        $this->writeTypes(<<<'TS'
        declare namespace StubbeDev {
        namespace LaravelStoli.Tests.Unit {
        export type ApiResponseData<TData> = { data: TData };
        export type InnerData = { id: number };
        }
        }
        TS);

        $response = $this->resolveResponse('classGeneric');

        self::assertSame('StubbeDev.LaravelStoli.Tests.Unit.ApiResponseData<StubbeDev.LaravelStoli.Tests.Unit.InnerData>', $response?->type);
        self::assertSame([], $response->imports);
    }

    public function test_an_ambient_type_in_a_declare_global_block_is_found(): void
    {
        // The GlobalNamespaceWriter wraps its namespaces in `declare global` once the file has imports.
        $this->writeTypes(<<<'TS'
        import type { Foo } from './foo';
        declare global {
        namespace StubbeDev.LaravelStoli.Tests.Unit {
        export type ApiResponseData<TData> = { data: TData };
        }
        }
        TS);

        self::assertSame('StubbeDev.LaravelStoli.Tests.Unit.ApiResponseData<null>', $this->resolveResponseType('nullGeneric'));
    }

    public function test_an_ambient_type_with_the_same_name_in_another_namespace_is_not_used(): void
    {
        $this->writeTypes(<<<'TS'
        declare namespace StubbeDev.LaravelStoli.Tests.Unit {
        export type ApiResponseData<TData> = { data: TData };
        }
        declare namespace App.Other {
        export type InnerData = { id: number };
        }
        TS);

        self::assertSame('StubbeDev.LaravelStoli.Tests.Unit.ApiResponseData', $this->resolveResponseType('classGeneric'));
    }

    private function writeTypes(string $source): void
    {
        file_put_contents(sys_get_temp_dir() . '/stoli-resolver-' . getmypid() . '/index.d.ts', $source);
    }

    private function resolveResponseType(string $method): ?string
    {
        return $this->resolveResponse($method)?->type;
    }

    private function resolveResponse(string $method): ?DataType
    {
        $route = new LaravelRoute('GET', '/stub', ['uses' => ResolverStubController::class . '@' . $method]);

        return self::create(SpatieDataTypeResolver::class)->resolve($route)['response'];
    }
}
