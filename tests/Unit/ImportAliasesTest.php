<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\ImportAliases;
use StubbeDev\LaravelStoli\StoliException;

final class ImportAliasesTest extends TestCase
{
    public function test_without_aliases_imports_are_relative(): void
    {
        $aliases = ImportAliases::fromConfig([]);

        self::assertSame('./stoli', $aliases->importPath('/app/frontend/src/@types', '/app/frontend/src/@types/stoli.ts'));
        self::assertSame('../types/index', $aliases->importPath('/app/resources/routes', '/app/resources/types/index.d.ts'));
    }

    public function test_a_file_under_an_aliased_directory_is_imported_through_the_alias(): void
    {
        $aliases = ImportAliases::fromConfig(['@' => '/app/frontend']);

        self::assertSame('@/src/@types/stoli', $aliases->importPath('/app/frontend/src/@types', '/app/frontend/src/@types/stoli.ts'));
        self::assertSame('@/src/@types/index', $aliases->importPath('/app/frontend/src/@types', '/app/frontend/src/@types/index.d.ts'));
        self::assertSame('@/src/@types/stoli-axios', $aliases->importPath('/elsewhere', '/app/frontend/src/@types/stoli-axios'));
    }

    public function test_a_trailing_slash_on_the_directory_makes_no_difference(): void
    {
        self::assertSame('@/api', ImportAliases::fromConfig(['@' => '/app/frontend/'])->importPath('/app/frontend', '/app/frontend/api.ts'));
    }

    public function test_the_deepest_aliased_directory_wins_whatever_the_order(): void
    {
        $types = '/app/frontend/src/@types';

        self::assertSame('@types/stoli', ImportAliases::fromConfig(['@' => '/app/frontend', '@types' => $types])->importPath($types, "$types/stoli.ts"));
        self::assertSame('@types/stoli', ImportAliases::fromConfig(['@types' => $types, '@' => '/app/frontend'])->importPath($types, "$types/stoli.ts"));
    }

    public function test_a_file_outside_every_aliased_directory_is_imported_relatively(): void
    {
        $aliases = ImportAliases::fromConfig(['@' => '/app/frontend']);

        self::assertSame('../frontend2/api', $aliases->importPath('/app/resources', '/app/frontend2/api.ts'));
        self::assertSame('./api', $aliases->importPath('/app/resources', '/app/resources/api.ts'));
    }

    public function test_windows_paths_are_aliased_with_forward_slashes(): void
    {
        $aliases = ImportAliases::fromConfig(['@' => 'C:\\app\\frontend']);

        self::assertSame('@/src/@types/stoli', $aliases->importPath('c:\\app\\frontend\\src', 'c:/app/frontend/src/@types/stoli.ts'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'not an array' => ['@', 'The "import_aliases" option is invalid: it must be an array of directories by alias, got string'];
        yield 'empty alias' => [['' => '/app/frontend'], 'The "import_aliases" option is invalid: the alias <> must be a non-empty string'];
        yield 'list instead of a map' => [['/app/frontend'], 'The "import_aliases" option is invalid: the alias <0> must be a non-empty string'];
        yield 'alias with a trailing slash' => [['@/' => '/app/frontend'], 'The "import_aliases" option is invalid: the alias <@/> must not end with a slash'];
        yield 'empty directory' => [['@' => ''], 'The "import_aliases" option is invalid: the directory of the alias <@> must be a non-empty string'];
        yield 'directory not a string' => [['@' => null], 'The "import_aliases" option is invalid: the directory of the alias <@> must be a non-empty string'];
    }

    #[DataProvider('invalid')]
    public function test_malformed_aliases_are_rejected(mixed $aliases, string $message): void
    {
        $this->expectException(StoliException::class);
        $this->expectExceptionMessage($message);

        ImportAliases::fromConfig($aliases);
    }
}
