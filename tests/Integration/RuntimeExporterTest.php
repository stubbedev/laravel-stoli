<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Exporters\RuntimeExporter;
use StubbeDev\LaravelStoli\FileRouteBuilder;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\StoliException;
use StubbeDev\LaravelStoli\Tests\TestCase;

/**
 * The route service is copied from resources/ next to the route files, with the module of
 * the configured client; like the route files it is only rewritten when it changed.
 */
final class RuntimeExporterTest extends TestCase
{
    private static function tmp(): string
    {
        return sys_get_temp_dir().'/stoli-runtime-test';
    }

    protected static function modules(): array
    {
        return [['match' => '*', 'name' => 'api']];
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

    private function publish(?string $client = null): void
    {
        config(['stoli.client' => $client]);
        self::forgetResolved();

        self::apply(self::create(RuntimeExporter::class)->generate(self::create(FileRouteBuilder::class)->files()));
    }

    private static function resource(string $file): string
    {
        return GeneratedFile::HEADER."\n\n".(string) file_get_contents(dirname(__DIR__, 2)."/resources/{$file}");
    }

    public function test_it_writes_the_route_service_and_the_module_of_the_client(): void
    {
        $this->publish('fetch');

        self::assertStringEqualsFile(self::tmp().'/stoli.ts', self::resource('stoli.ts'));
        self::assertStringEqualsFile(self::tmp().'/stoli-fetch.ts', self::resource('stoli-fetch.ts'));
        self::assertFileDoesNotExist(self::tmp().'/stoli-axios.ts');
    }

    public function test_switching_the_client_removes_the_module_of_the_other(): void
    {
        $this->publish('axios');
        $this->publish('fetch');

        self::assertFileDoesNotExist(self::tmp().'/stoli-axios.ts');
        self::assertFileExists(self::tmp().'/stoli-fetch.ts');

        $this->publish();

        self::assertFileDoesNotExist(self::tmp().'/stoli-fetch.ts');
        self::assertFileExists(self::tmp().'/stoli.ts');
    }

    public function test_the_axios_switch_of_earlier_versions_still_picks_axios(): void
    {
        config(['stoli.axios' => true]);

        $this->publish();

        self::assertFileExists(self::tmp().'/stoli-axios.ts');
    }

    public function test_an_unknown_client_is_rejected(): void
    {
        $this->expectException(StoliException::class);
        $this->expectExceptionMessage("The \"client\" option must be one of 'axios', 'fetch' or null, got string");

        $this->publish('ky');
    }

    public function test_the_files_of_earlier_versions_are_removed_but_not_a_hand_written_one(): void
    {
        $filesystem = new Filesystem;
        $filesystem->put(self::tmp().'/stoli.js', "export class RouteService {\n");
        $filesystem->put(self::tmp().'/stoli.d.ts', "// mine\nexport interface Route {}\n");

        $this->publish();

        self::assertFileDoesNotExist(self::tmp().'/stoli.js');
        self::assertFileExists(self::tmp().'/stoli.d.ts');
    }

    public function test_unchanged_files_are_not_rewritten_and_changed_ones_are_restored(): void
    {
        $this->publish('axios');

        touch(self::tmp().'/stoli.ts', 100);
        (new Filesystem)->put(self::tmp().'/stoli-axios.ts', '// edited');
        clearstatcache();

        $this->publish('axios');
        clearstatcache();

        self::assertSame(100, filemtime(self::tmp().'/stoli.ts'));
        self::assertStringEqualsFile(self::tmp().'/stoli-axios.ts', self::resource('stoli-axios.ts'));
    }
}
