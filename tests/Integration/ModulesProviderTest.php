<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StubbeDev\LaravelStoli\ModulesProvider;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Tests\TestCase;

final class ModulesProviderTest extends TestCase
{
    protected static function modules(): array
    {
        return [];
    }

    /**
     * @param  array<mixed>  $modules
     */
    private static function provider(array $modules): ModulesProvider
    {
        return new ModulesProvider(new StoliConfig(['modules' => $modules]));
    }

    public function test_it_fills_in_the_defaults(): void
    {
        $module = self::provider([['name' => 'api']])->modules()->sole();

        self::assertSame('*', $module->match());
        self::assertSame('http://localhost', $module->rootUrl());
        self::assertSame('', $module->prefix());
        self::assertTrue($module->absolute());
        self::assertFalse($module->standalone());
        self::assertTrue($module->matchesName('anything'));
    }

    public function test_a_single_names_pattern_is_accepted(): void
    {
        $module = self::provider([['name' => 'app', 'names' => 'app.*']])->modules()->sole();

        self::assertTrue($module->matchesName('app.home'));
        self::assertFalse($module->matchesName('api.users'));
    }

    public function test_a_duplicate_name_is_rejected(): void
    {
        $this->expectExceptionObject(StoliException::moduleAlreadyExists('api'));

        self::provider([['name' => 'api'], ['name' => 'api', 'match' => '/api']])->modules();
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidModules(): iterable
    {
        yield 'not an array' => ['api', 'a module is an array of options'];
        yield 'no name' => [['match' => '/api'], 'the "name" option is required'];
        yield 'empty name' => [['name' => ''], 'the "name" option is required'];
        yield 'non-string option' => [['name' => 'api', 'path' => 42], 'the "path" option must be a string'];
        yield 'non-string pattern' => [['name' => 'api', 'names' => ['app.*', 1]], 'the "names" option must be a pattern or a list of patterns'];
    }

    #[DataProvider('invalidModules')]
    public function test_a_malformed_module_is_rejected(mixed $module, string $reason): void
    {
        $this->expectExceptionObject(StoliException::invalidModule(0, $reason));

        self::provider([$module])->modules();
    }
}
