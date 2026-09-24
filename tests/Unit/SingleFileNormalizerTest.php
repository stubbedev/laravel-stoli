<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Normalizers\MultipleFilesNormalizer;
use StubbeDev\LaravelStoli\Normalizers\SingleFileNormalizer;
use StubbeDev\LaravelStoli\StoliConfig;
use Illuminate\Support\Collection;

final class SingleFileNormalizerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function makeRoute(string $name, string $uri): Route
    {
        return new Route(
            name: $name,
            rootUrl: 'http://localhost',
            uri: $uri,
            prefix: null,
            absolute: false,
            host: null,
        );
    }

    /**
     * @param  list<Route>  $routes
     */
    private static function makeFile(string $name, array $routes, bool $standalone = false): File
    {
        return new File($name, 'resources/routes', new Collection($routes), $standalone);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function makeStoliConfig(array $overrides = []): StoliConfig
    {
        return new StoliConfig(array_merge([
            'split' => false,
            'single' => ['name' => 'api'],
            'modules' => [],
            'axios' => false,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // SingleFileNormalizer
    // -------------------------------------------------------------------------

    public function test_single_normalizer_merges_all_files_into_one(): void
    {
        $config = self::makeStoliConfig();
        $normalizer = new SingleFileNormalizer($config);

        $files = new Collection([
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

    public function test_single_normalizer_uses_config_name(): void
    {
        $config = self::makeStoliConfig([
            'single' => ['name' => 'routes'],
        ]);
        $normalizer = new SingleFileNormalizer($config);

        $files = new Collection([self::makeFile('api', [])]);
        $result = $normalizer->normalize($files);

        /** @var File $merged */
        $merged = $result->values()[0];

        self::assertSame('routes', $merged->name());
        // Path is derived from the typescript-transformer output directory;
        // without a container in unit tests it resolves to null.
        self::assertNull($merged->path());
    }

    public function test_single_normalizer_keeps_a_standalone_file_out_of_the_merge(): void
    {
        $normalizer = new SingleFileNormalizer(self::makeStoliConfig());

        $pages = self::makeFile('pages', [self::makeRoute('app.settings', 'settings')], standalone: true);
        $result = $normalizer->normalize(new Collection([
            self::makeFile('store', [self::makeRoute('store.cart.show', 'api/store/cart')]),
            $pages,
            self::makeFile('admin', [self::makeRoute('admin.users.list', 'api/admin/users')]),
        ]));

        self::assertSame(2, $result->count());

        /** @var File $merged */
        [$merged, $standalone] = $result->all();

        self::assertSame('api', $merged->name());
        self::assertSame(
            ['store.cart.show', 'admin.users.list'],
            $merged->routes()->map(static fn (Route $route) => $route->name())->all(),
        );
        self::assertSame($pages, $standalone);
    }

    // -------------------------------------------------------------------------
    // MultipleFilesNormalizer
    // -------------------------------------------------------------------------

    public function test_multiple_normalizer_is_identity(): void
    {
        $normalizer = new MultipleFilesNormalizer;

        $files = new Collection([
            self::makeFile('store', []),
            self::makeFile('admin', []),
        ]);

        $result = $normalizer->normalize($files);

        self::assertSame(2, $result->count());
    }

    public function test_single_normalizer_writes_no_combined_file_when_every_module_is_standalone(): void
    {
        $normalizer = new SingleFileNormalizer(self::makeStoliConfig());

        $pages = self::makeFile('pages', [self::makeRoute('app.settings', 'settings')], standalone: true);

        self::assertSame([$pages], $normalizer->normalize(new Collection([$pages]))->all());
    }
}
