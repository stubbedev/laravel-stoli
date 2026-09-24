<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Utils;

final class UtilsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'posix' => ['/srv/app', true];
        yield 'windows drive, backslash' => ['C:\\app\\types', true];
        yield 'windows drive, slash' => ['c:/app/types', true];
        yield 'windows unc' => ['\\\\server\\share', true];
        yield 'relative' => ['resources/types', false];
        yield 'relative windows' => ['resources\\types', false];
        yield 'drive-relative' => ['C:types', false];
    }

    #[DataProvider('paths')]
    public function test_it_tells_absolute_paths_apart(string $path, bool $absolute): void
    {
        self::assertSame($absolute, Utils::isAbsolutePath($path));
    }

    public function test_an_absolute_path_is_left_as_it_is(): void
    {
        self::assertSame('C:\\app\\types', Utils::absolutePath('C:\\app\\types'));
    }

    public function test_import_paths_are_relative_with_forward_slashes(): void
    {
        self::assertSame('../types/index', Utils::relativeImportPath('/app/resources/routes', '/app/resources/types/index.d.ts'));
        self::assertSame('./stoli', Utils::relativeImportPath('/app/types', '/app/types/stoli.d.ts'));
    }

    public function test_windows_import_paths_are_relative_with_forward_slashes(): void
    {
        self::assertSame(
            '../types/index',
            Utils::relativeImportPath('C:\\app\\resources\\routes', 'C:\\app\\resources\\types\\index.d.ts'),
        );

        // Drive letters differ in case between tools; they are the same drive.
        self::assertSame('./stoli', Utils::relativeImportPath('c:\\app\\types', 'C:/app/types/stoli.d.ts'));
    }
}
