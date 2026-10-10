<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;
use StubbeDev\LaravelStoli\Publisher;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\UserController;
use StubbeDev\LaravelStoli\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the typescript-transformer over the fixtures and publishes every generated file
 * for the fixture routes into tests/typescript/build, then compiles them with tsc
 * against tests/typescript/usage.ts, which asserts the types the routes narrow to.
 *
 * It does so once per client: the one usage.ts compiling against either router is what
 * makes switching the `client` option a config change.
 *
 * Needs `npm ci --prefix tests/typescript`; without it the test is skipped, unless
 * STOLI_REQUIRE_TSC is set, as it is in CI.
 */
final class GeneratedTypeScriptTest extends TestCase
{
    private const PROJECT = __DIR__.'/../typescript';

    private static function build(): string
    {
        return self::PROJECT.'/build';
    }

    protected static function modules(): array
    {
        return [['match' => '/api', 'name' => 'api', 'rootUrl' => 'https://app.test']];
    }

    protected static function config(): array
    {
        return [
            'split' => true,
            'urls' => true,
            'constants' => [
                'paths' => [dirname(__DIR__).'/Fixtures/Constants'],
                'attributes' => [TypeScriptConstants::class],
            ],
        ];
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('api/users', [UserController::class, 'index'])->name('users.index');
        $router->post('api/users', [UserController::class, 'store'])->name('users.store');
        $router->get('api/users/paginated', [UserController::class, 'paginated'])->name('users.paginated');
        $router->get('api/users/cursor', [UserController::class, 'cursor'])->name('users.cursor');
        $router->get('api/users/wrapped', [UserController::class, 'wrapped'])->name('users.wrapped');
        $router->get('api/users/list', [UserController::class, 'list'])->name('users.list');
        $router->get('api/users/wrapped-by-class', [UserController::class, 'wrappedByClass'])->name('users.wrappedByClass');
        $router->get('api/users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
        $router->put('api/users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
        $router->get('api/kinds/{kind}', static fn () => [])->whereIn('kind', ['a', 'b'])->name('kinds.show');
        $router->get('api/versions/{version}', static fn () => [])->whereIn('version', ['1', '2'])->name('versions.show');
        $router->get('api/posts/{page?}', static fn () => [])->name('posts.index');
        foreach (['wrappedNull', 'wrappedEnum', 'wrappedNested', 'wrappedUntagged', 'keyed', 'collection', 'shape', 'maybe', 'nothing'] as $method) {
            $router->get("api/shapes/{$method}", [UserController::class, $method])->name("shapes.{$method}");
        }

        $router->get('api/bound/{post}/{article}/{byTitle:title}', [UserController::class, 'bound'])->name('bound.show');
        $router->get('api/users/self-responding', [UserController::class, 'selfResponding'])->name('users.selfResponding');
        $router->post('api/orders', [UserController::class, 'order'])->name('orders.store');
        $router->post('api/categories', [UserController::class, 'category'])->name('categories.store');
        $router->post('api/articles', [UserController::class, 'article'])->name('articles.store');
        $router->put('api/profile', [UserController::class, 'profile'])->name('profile.update');
        $router->get('api/statuses/{status}/{page}', [UserController::class, 'byStatus'])->name('statuses.show');
        $router->domain('{account}.app.test')->get('api/dashboard', static fn () => [])->name('tenant.dashboard');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $filesystem = new Filesystem;
        $filesystem->deleteDirectory(self::build());
        $filesystem->delete(dirname(__DIR__, 2).'/.cache');
        $filesystem->ensureDirectoryExists(self::build());

        self::useTransformer(self::build());
        self::transform();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clients(): iterable
    {
        yield 'axios' => ['axios'];
        yield 'fetch' => ['fetch'];
    }

    #[DataProvider('clients')]
    public function test_the_generated_files_compile_to_the_expected_types(string $client): void
    {
        config(['stoli.client' => $client]);
        self::forgetResolved();

        self::create(Publisher::class)->publish();

        foreach (['index.d.ts', 'stoli.ts', "stoli-{$client}.ts", 'api.ts', 'api.urls.ts', 'router.ts', 'constants.ts'] as $file) {
            self::assertFileExists(self::build()."/{$file}");
        }

        $tsc = self::PROJECT.'/node_modules/.bin/tsc';

        if (! is_file($tsc)) {
            if (getenv('STOLI_REQUIRE_TSC') !== false) {
                self::fail('tsc is not installed; run npm ci --prefix tests/typescript');
            }

            self::markTestSkipped('tsc is not installed; run npm ci --prefix tests/typescript');
        }

        $process = new Process([$tsc, '-p', self::PROJECT]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }
}
