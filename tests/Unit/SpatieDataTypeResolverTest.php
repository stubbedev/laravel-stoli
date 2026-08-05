<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use Illuminate\Routing\Route as LaravelRoute;
use LogicException;
use Spatie\LaravelData\Data;
use StubbeDev\LaravelStoli\SpatieDataTypeResolver;
use StubbeDev\LaravelStoli\Tests\TestCase;

class ApiResponseData extends Data
{
}

class InnerData extends Data
{
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
            "export type ApiResponseData<TData> = { data: TData };\nexport type InnerData = { id: number };\n",
        );

        app()->instance(
            'Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig',
            (object) ['outputDirectory' => $directory],
        );
    }

    public function test_it_renders_a_non_class_generic_argument_literally(): void
    {
        self::assertSame('ApiResponseData<null>', $this->resolveResponseType('nullGeneric'));
    }

    public function test_it_resolves_a_data_class_generic_argument(): void
    {
        self::assertSame('ApiResponseData<InnerData>', $this->resolveResponseType('classGeneric'));
    }

    private function resolveResponseType(string $method): ?string
    {
        $route = new LaravelRoute('GET', '/stub', ['uses' => ResolverStubController::class . '@' . $method]);

        return (new SpatieDataTypeResolver())->resolve($route)['response']['type'] ?? null;
    }
}
