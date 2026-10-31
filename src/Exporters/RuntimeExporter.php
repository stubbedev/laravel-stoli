<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use StubbeDev\LaravelStoli\Client;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\ImportAliases;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Query;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

/**
 * The route service, stoli.ts, next to the route files that import it, with the modules
 * of the configured client and query library beside it. A module no longer configured, and
 * the stoli.js and stoli.d.ts of versions before stoli.ts, are removed.
 */
final readonly class RuntimeExporter
{
    public const MODULE = 'stoli';

    /**
     * What versions before stoli.ts wrote, by the text they start with.
     */
    private const LEGACY = [
        'stoli.js' => 'export class RouteService {',
        'stoli.d.ts' => 'export interface Route {',
    ];

    public function __construct(
        private Filesystem $filesystem,
        private StoliConfig $config,
    ) {}

    /**
     * @param  list<File>  $files
     */
    public function generate(array $files): Generation
    {
        $directories = array_unique(array_filter([
            $this->config->defaultOutputPath(),
            ...array_map(static fn (File $file): string => $file->runtime, $files),
        ]));
        $selected = [$this->config->client(), $this->config->query()];
        $aliases = $this->config->importAliases();
        $generated = [];
        $removals = [];

        try {
            foreach ($directories as $directory) {
                $generated[] = $this->copy($directory, self::MODULE, $aliases);

                foreach ([...Client::cases(), ...Query::cases()] as $case) {
                    if (in_array($case, $selected, true)) {
                        $generated[] = $this->copy($directory, $case->module(), $aliases);
                    } else {
                        $removals[] = new Removal(join_paths($directory, "{$case->module()}.ts"));
                    }
                }

                foreach (self::LEGACY as $file => $legacy) {
                    $removals[] = new Removal(join_paths($directory, $file), $legacy);
                }
            }
        } catch (Throwable $error) {
            throw StoliException::cantExportRuntime($error);
        }

        return new Generation($generated, $removals);
    }

    /**
     * The resources import one another, and in their examples the route files, from their
     * own directory; those imports are taken through the aliases like any generated one.
     */
    private function copy(string $directory, string $module, ImportAliases $aliases): GeneratedFile
    {
        $contents = preg_replace_callback(
            "~ from '\./([^'/]+)'~",
            static fn (array $import): string => " from '".$aliases->importPath($directory, join_paths($directory, $import[1]))."'",
            $this->filesystem->get(join_paths($this->config->resourcesPath(), "{$module}.ts")),
        ) ?? throw new RuntimeException("Could not rewrite the imports of {$module}.ts: ".preg_last_error_msg());

        return new GeneratedFile(join_paths($directory, "{$module}.ts"), $contents, format: false);
    }
}
