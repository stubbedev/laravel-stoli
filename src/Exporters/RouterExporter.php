<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\Utils;

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
        $routed = array_values(array_filter($files, static fn (File $file): bool => ! $file->standalone));
        $generated = [];
        $removals = [];

        if ($client !== null) {
            foreach ($routed as $file) {
                $generated[] = new GeneratedFile(self::path($file, count($routed) > 1), self::router($file, $client->module()));
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

    private static function router(File $file, string $module): string
    {
        $client = Utils::relativeImportPath(Utils::absolutePath($file->directory), join_paths(Utils::absolutePath($file->runtime), $module));

        return <<<TS
        import { createRouter } from '{$client}';
        import routes from './{$file->name}';

        export const Stoli = createRouter(routes);

        export default Stoli;

        TS;
    }
}
