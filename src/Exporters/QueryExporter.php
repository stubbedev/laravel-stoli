<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\Compilers\TypeScript;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\ImportAliases;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Query;
use StubbeDev\LaravelStoli\StoliConfig;

use function Illuminate\Filesystem\join_paths;

final readonly class QueryExporter
{
    public function __construct(private StoliConfig $config) {}

    /**
     * @param  list<File>  $files
     */
    public function generate(array $files): Generation
    {
        $query = $this->config->query();
        $aliases = $this->config->importAliases();
        $routed = RouterExporter::routed($files);
        $generated = [];
        $removals = [];

        if ($query !== null) {
            foreach ($routed as $router => $file) {
                $generated[] = new GeneratedFile(self::path($file), self::compile($file, $router, $query, count($routed) > 1, $aliases));
            }
        }

        foreach ($files as $file) {
            $removals[] = new Removal(self::path($file));
        }

        return new Generation($generated, $removals);
    }

    private static function path(File $file): string
    {
        return join_paths($file->directory, "{$file->name}.query.ts");
    }

    private static function compile(File $file, string $router, Query $query, bool $namespaced, ImportAliases $aliases): string
    {
        $definitions = Str::studly($file->name).'Routes';
        $runtime = $aliases->importPath($file->directory, join_paths($file->runtime, $query->module()));
        $routes = $aliases->importPath($file->directory, $file->path());
        $stoli = $aliases->importPath($file->directory, $router);
        $namespace = $namespaced ? '['.TypeScript::string($file->name).']' : '[]';

        return <<<TS
        import { createQueries } from '{$runtime}';
        import type { {$definitions} } from '{$routes}';
        import { Stoli } from '{$stoli}';

        export const { stoliQuery, stoliKey } = createQueries<{$definitions}, {$namespace}>(Stoli.get, {$namespace});

        TS;
    }
}
