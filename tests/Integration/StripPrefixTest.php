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
 * Verifies that the stripPrefix module option strips route name prefixes in the
 * generated TypeScript output.
 */
final class StripPrefixTest extends TestCase
{
    protected static function modules(): array
    {
        return [
            [
                'match'       => 'api/store',
                'name'        => 'store',
                'path'        => 'resources/routes',
                'stripPrefix' => 'store.',
            ],
        ];
    }

    public function test_route_names_have_prefix_stripped(): void
    {
        $builder  = static::create(FileRouteBuilder::class);
        $compiler = new TypeScriptFileCompiler(new JsonFileCompiler(), new ConstraintTypeMapper());

        /** @var File $file */
        $file   = $builder->files()->values()[0];
        $output = $compiler->compile($file);

        // Should appear without the 'store.' prefix
        self::assertStringContainsString("'products.list'", $output);
        self::assertStringContainsString("'cart.show'", $output);

        // Should NOT appear with the full prefix
        self::assertStringNotContainsString("'store.products.list'", $output);
        self::assertStringNotContainsString("'store.cart.show'", $output);
    }
}
