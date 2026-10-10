<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Client;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\StoliConfig;
use StubbeDev\LaravelStoli\StoliException;
use Throwable;

use function Illuminate\Filesystem\join_paths;

/**
 * The route service, stoli.ts, next to the route files that import it, with the module
 * of the configured client beside it. The module of a client no longer configured, and
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
        $client = $this->config->client();
        $generated = [];
        $removals = [];

        try {
            foreach ($directories as $directory) {
                $generated[] = $this->copy($directory, self::MODULE);

                foreach (Client::cases() as $case) {
                    if ($case === $client) {
                        $generated[] = $this->copy($directory, $case->module());
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

    private function copy(string $directory, string $module): GeneratedFile
    {
        return new GeneratedFile(
            join_paths($directory, "{$module}.ts"),
            $this->filesystem->get(join_paths($this->config->resourcesPath(), "{$module}.ts")),
            format: false,
        );
    }
}
