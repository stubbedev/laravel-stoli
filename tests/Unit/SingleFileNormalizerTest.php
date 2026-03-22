<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Normalizers\MultipleFilesNormalizer;
use StubbeDev\LaravelStoli\Normalizers\SingleFileNormalizer;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\Support\ArrayList;

final class SingleFileNormalizerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function makeRoute(string $name, string $uri): Route
    {
        return new Route(
            name:     $name,
            rootUrl:  'http://localhost',
            uri:      $uri,
            prefix:   null,
            absolute: false,
            host:     null,
        );
    }

    private static function makeFile(string $name, array $routes): File
    {
        return new File($name, 'resources/routes', new ArrayList($routes));
    }

    private static function makeStoliConfig(array $overrides = []): StoliConfig
    {
        return new StoliConfig(array_merge([
            'library'   => 'resources/routes',
            'split'     => false,
            'single'    => ['name' => 'api', 'path' => 'resources/routes'],
            'modules'   => [],
            'axios'     => false,
            'resources' => __DIR__ . '/../../resources',
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // SingleFileNormalizer
    // -------------------------------------------------------------------------

    public function test_single_normalizer_merges_all_files_into_one(): void
    {
        $config = self::makeStoliConfig();
        $normalizer = new SingleFileNormalizer($config);

        $files = new ArrayList([
            self::makeFile('store', [
                self::makeRoute('store.products.list', 'api/store/products'),
                self::makeRoute('store.cart.show', 'api/store/cart'),
            ]),
            self::makeFile('admin', [
                self::makeRoute('admin.users.list', 'api/admin/users'),
            ]),
        ]);

        $result = $normalizer->normalize($files);

        self::assertSame(1, $result->count());

        /** @var File $merged */
        $merged = $result->values()[0];

        self::assertSame('api', $merged->name());
        self::assertSame(3, $merged->routes()->count());
    }

    public function test_single_normalizer_uses_config_name_and_path(): void
    {
        $config = self::makeStoliConfig([
            'single' => ['name' => 'routes', 'path' => 'resources/js'],
        ]);
        $normalizer = new SingleFileNormalizer($config);

        $files  = new ArrayList([self::makeFile('api', [])]);
        $result = $normalizer->normalize($files);

        /** @var File $merged */
        $merged = $result->values()[0];

        self::assertSame('routes', $merged->name());
        self::assertSame('resources/js', $merged->path());
    }

    // -------------------------------------------------------------------------
    // MultipleFilesNormalizer
    // -------------------------------------------------------------------------

    public function test_multiple_normalizer_is_identity(): void
    {
        $normalizer = new MultipleFilesNormalizer();

        $files = new ArrayList([
            self::makeFile('store', []),
            self::makeFile('admin', []),
        ]);

        $result = $normalizer->normalize($files);

        self::assertSame(2, $result->count());
    }
}
