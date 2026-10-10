<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Events\FileUpdatedWatchEvent;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\UserController;
use StubbeDev\LaravelStoli\Tests\TestCase;
use StubbeDev\LaravelStoli\Transformer\StoliTransformedProvider;

/**
 * `typescript:transform` generates Stoli's files from the very types it writes, and
 * `stoli:generate --check` agrees with what it wrote.
 */
final class TransformIntegrationTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-transform-test';
    }

    protected static function modules(): array
    {
        return [['match' => 'api', 'name' => 'api', 'rootUrl' => 'https://app.test']];
    }

    protected static function config(): array
    {
        return ['split' => true, 'client' => 'fetch', 'constants' => ['enabled' => false]];
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TypeScriptTransformerServiceProvider::class];
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('api/users/{user}', [UserController::class, 'show'])->name('users.show');
        $router->post('api/users', [UserController::class, 'store'])->name('users.store');
    }

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::setUp();

        self::useTransformer(self::tmp());
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    public function test_typescript_transform_generates_the_stoli_files_too(): void
    {
        $this->command('typescript:transform')->assertSuccessful();

        foreach (['index.d.ts', 'stoli.ts', 'stoli-fetch.ts', 'api.ts', 'router.ts'] as $file) {
            self::assertFileExists(self::tmp()."/{$file}");
        }

        self::assertStringStartsWith(GeneratedFile::HEADER, (string) file_get_contents(self::tmp().'/api.ts'));
        self::assertStringContainsString('response: StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data.UserData', (string) file_get_contents(self::tmp().'/api.ts'));
        self::assertStringNotContainsString('stoli', (string) file_get_contents(self::tmp().'/index.d.ts'), 'Stoli adds nothing to the types file');
    }

    public function test_the_check_agrees_with_what_the_transformer_wrote(): void
    {
        $this->command('typescript:transform')->assertSuccessful();

        $this->command('stoli:generate', ['--check' => true])
            ->expectsOutput('The generated files are up to date')
            ->assertSuccessful();
    }

    public function test_the_check_fails_on_a_changed_or_missing_file(): void
    {
        $this->command('stoli:generate')->assertSuccessful();
        (new Filesystem)->put(self::tmp().'/api.ts', '// edited');
        (new Filesystem)->delete(self::tmp().'/router.ts');

        $this->command('stoli:generate', ['--check' => true])
            ->expectsOutputToContain(self::tmp().'/api.ts')
            ->expectsOutputToContain(self::tmp().'/router.ts')
            ->assertFailed();

        self::assertStringEqualsFile(self::tmp().'/api.ts', '// edited', 'a check writes nothing');
    }

    public function test_switching_the_client_removes_the_other_one_inside_the_transformer_too(): void
    {
        config(['stoli.client' => 'axios']);
        self::forgetResolved();
        $this->command('typescript:transform')->assertSuccessful();

        config(['stoli.client' => 'fetch']);
        self::forgetResolved();
        $this->command('typescript:transform')->assertSuccessful();

        self::assertFileDoesNotExist(self::tmp().'/stoli-axios.ts');
        self::assertFileExists(self::tmp().'/stoli-fetch.ts');
    }

    public function test_it_can_be_turned_off(): void
    {
        config(['stoli.transform' => false]);
        app()->forgetInstance(TypeScriptTransformerConfig::class);
        self::useTransformer(self::tmp());

        $this->command('typescript:transform')->assertSuccessful();

        self::assertFileExists(self::tmp().'/index.d.ts');
        self::assertFileDoesNotExist(self::tmp().'/api.ts');
    }

    public function test_the_routes_are_watched_in_watch_mode(): void
    {
        $routes = base_path('routes');
        $created = ! is_dir($routes) && mkdir($routes);

        try {
            self::useTransformer(self::tmp());

            self::assertContains($routes, app()->make(TypeScriptTransformerConfig::class)->directoriesToWatch);
        } finally {
            if ($created) {
                rmdir($routes);
            }
        }
    }

    public function test_it_is_added_once(): void
    {
        $providers = array_filter(
            app()->make(TypeScriptTransformerConfig::class)->transformedProviders,
            static fn (object $provider): bool => $provider instanceof StoliTransformedProvider,
        );

        self::assertCount(1, $providers);
    }

    public function test_in_watch_mode_a_php_change_starts_the_transformer_over(): void
    {
        $provider = new StoliTransformedProvider(app(), [dirname(__DIR__).'/Fixtures', '/missing']);
        $collection = new TransformedCollection;

        self::assertSame([dirname(__DIR__).'/Fixtures'], $provider->directoriesToWatch());
        self::assertTrue($provider->handleWatchEvent(new FileUpdatedWatchEvent('/app/routes/api.php'), $collection)?->completeRefresh);
        self::assertNull($provider->handleWatchEvent(new FileUpdatedWatchEvent('/app/routes/readme.md'), $collection));
    }
}
