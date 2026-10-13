<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generator;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * With `import_aliases` set, every generated file imports the generated files under an
 * aliased directory through the alias, the way the application's own code does.
 */
final class ImportAliasesTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-aliases-test';
    }

    private static function types(): string
    {
        return self::tmp().'/frontend/src/@types';
    }

    protected static function modules(): array
    {
        return [
            ['match' => 'api/store', 'name' => 'store'],
            ['match' => 'api/admin', 'name' => 'admin', 'path' => self::tmp().'/frontend/src/admin'],
            ['name' => 'pages', 'names' => 'admin.*', 'standalone' => true],
            ['match' => 'api/store', 'name' => 'outside', 'path' => self::tmp().'/resources'],
        ];
    }

    protected static function config(): array
    {
        return ['split' => true, 'client' => 'axios', 'urls' => true];
    }

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::setUp();

        self::useTransformer(self::types());
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(self::tmp());
        (new Filesystem)->delete(dirname(__DIR__, 2).'/.cache');

        parent::tearDown();
    }

    /**
     * The generated files by path, with the given `import_aliases`, or without the option for null.
     *
     * @param  array<string, string>|null  $aliases
     * @return array<string, string>
     */
    private function generate(?array $aliases): array
    {
        if ($aliases !== null) {
            config(['stoli.import_aliases' => $aliases]);
        }

        self::forgetResolved();

        $files = [];

        foreach (self::create(Generator::class)->generate()->files as $file) {
            $files[$file->path] = substr($file->contents, strlen(GeneratedFile::HEADER."\n\n"));
        }

        return $files;
    }

    public function test_every_import_of_a_file_under_an_aliased_directory_goes_through_the_alias(): void
    {
        $files = $this->generate(['@' => self::tmp().'/frontend']);
        $types = self::types();
        $admin = self::tmp().'/frontend/src/admin';

        self::assertStringContainsString(" * import { createRoute } from '@/src/@types/stoli';\n * import routes from '@/src/@types/api';", $files["$types/stoli.ts"]);
        self::assertStringContainsString("} from '@/src/@types/stoli';", $files["$types/stoli-axios.ts"]);
        self::assertStringStartsWith("import type { NamesFor, ParamsOf, ResponsesOf, RouteMap, StatusesOf } from '@/src/@types/stoli';", $files["$types/store.ts"]);
        self::assertStringStartsWith("import type { NamesFor, ParamsOf, ResponsesOf, RouteMap, StatusesOf } from '@/src/@types/stoli';", $files["$types/pages.ts"]);
        self::assertStringStartsWith("import type { NamesFor, ParamsOf, ResponsesOf, RouteMap, StatusesOf } from '@/src/@types/stoli';", $files["$admin/admin.ts"]);
        self::assertStringStartsWith("import { createRouter } from '@/src/@types/stoli-axios';\nimport routes from '@/src/@types/store';", $files["$types/store.router.ts"]);
        self::assertStringStartsWith("import { createRouter } from '@/src/@types/stoli-axios';\nimport routes from '@/src/admin/admin';", $files["$admin/admin.router.ts"]);
        self::assertStringStartsWith("import { url, type Arguments } from '@/src/@types/stoli';\nimport type { StoreRouteParams } from '@/src/@types/store';", $files["$types/store.urls.ts"]);
        self::assertStringStartsWith("import { url, type Arguments } from '@/src/@types/stoli';\nimport type { PagesRouteParams } from '@/src/@types/pages';", $files["$types/pages.urls.ts"]);

        foreach ($files as $path => $contents) {
            if (str_starts_with($path, self::tmp().'/frontend/')) {
                self::assertDoesNotMatchRegularExpression("~ from '\.~", $contents, "$path imports relatively");
            }
        }
    }

    public function test_a_file_outside_every_aliased_directory_is_still_imported_relatively(): void
    {
        $files = $this->generate(['@' => self::tmp().'/frontend']);
        $outside = self::tmp().'/resources';

        self::assertStringStartsWith("import { createRouter } from '@/src/@types/stoli-axios';\nimport routes from './outside';", $files["$outside/outside.router.ts"]);
        self::assertStringStartsWith("import { url, type Arguments } from '@/src/@types/stoli';\nimport type { OutsideRouteParams } from './outside';", $files["$outside/outside.urls.ts"]);
    }

    public function test_without_aliases_the_output_is_the_same_as_without_the_option(): void
    {
        $without = $this->generate(null);

        self::assertSame($without, $this->generate([]));
        self::assertStringStartsWith("import { createRouter } from '../frontend/src/@types/stoli-axios';", $without[self::tmp().'/resources/outside.router.ts'] ?? '');
    }

    public function test_a_malformed_alias_is_rejected(): void
    {
        $this->expectException(StoliException::class);
        $this->expectExceptionMessage('The "import_aliases" option is invalid: the alias <@/> must not end with a slash');

        $this->generate(['@/' => self::tmp().'/frontend']);
    }
}
