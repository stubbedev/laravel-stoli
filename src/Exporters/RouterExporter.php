<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\ImportAliases;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\StoliConfig;

use function Illuminate\Filesystem\join_paths;

/**
 * A router for every route file that is not standalone, built on the module of
 * the configured client: `router.ts`, or `<module>.router.ts` when there are several.
 * The routers no longer wanted are removed.
 */
final readonly class RouterExporter
{
    public function __construct(private StoliConfig $config) {}

    /**
     * @param  list<File>  $files
     */
    public function generate(array $files): Generation
    {
        $client = $this->config->client();
        $aliases = $this->config->importAliases();
        $routed = array_values(array_filter($files, static fn (File $file): bool => ! $file->standalone));
        $generated = [];
        $removals = [];

        if ($client !== null) {
            foreach ($routed as $file) {
                $generated[] = new GeneratedFile(self::path($file, count($routed) > 1), self::router($file, $client->module(), $aliases));
            }
        }

        foreach ($files as $file) {
            $removals[] = new Removal(self::path($file, false));
            $removals[] = new Removal(self::path($file, true));
        }

        return new Generation($generated, $removals);
    }

    private static function path(File $file, bool $named): string
    {
        return join_paths($file->directory, $named ? "{$file->name}.router.ts" : 'router.ts');
    }

    private static function router(File $file, string $module, ImportAliases $aliases): string
    {
        $client = $aliases->importPath($file->directory, join_paths($file->runtime, $module));
        $routes = $aliases->importPath($file->directory, $file->path());

        return <<<TS
        import { createRouter } from '{$client}';
        import routes from '{$routes}';

        export const Stoli = createRouter(routes);

        export default Stoli;

        TS;
    }
}
