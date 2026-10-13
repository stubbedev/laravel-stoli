<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Exporters;

use Illuminate\Support\Str;
use StubbeDev\LaravelStoli\Compilers\TypeScript;
use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use StubbeDev\LaravelStoli\ImportAliases;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\StoliConfig;

use function Illuminate\Filesystem\join_paths;

/**
 * With the `urls` option on, `<module>.urls.ts` next to each route file: a function per
 * route building its URL, which a bundler leaves out when it is not used. The route data
 * sits in the function itself, and the types come from the route file, imported as
 * types only, so nothing else is pulled in.
 */
final readonly class UrlsExporter
{
    public function __construct(private StoliConfig $config) {}

    /**
     * @param  list<File>  $files
     */
    public function generate(array $files): Generation
    {
        $aliases = $this->config->importAliases();
        $generated = [];
        $removals = [];

        foreach ($files as $file) {
            $path = join_paths($file->directory, "{$file->name}.urls.ts");

            if ($this->config->urls()) {
                $generated[] = new GeneratedFile($path, self::compile($file, $aliases));
            } else {
                $removals[] = new Removal($path);
            }
        }

        return new Generation($generated, $removals);
    }

    private static function compile(File $file, ImportAliases $aliases): string
    {
        $params = Str::studly($file->name).'RouteParams';
        $stoli = $aliases->importPath($file->directory, join_paths($file->runtime, RuntimeExporter::MODULE));
        $routes = $aliases->importPath($file->directory, $file->path());
        $taken = [];
        $functions = [];

        foreach ($file->routes as $route) {
            $identifier = TypeScript::identifier($route->name);

            for ($candidate = $identifier, $suffix = 2; isset($taken[$candidate]); $suffix++) {
                $candidate = $identifier.$suffix;
            }

            $taken[$candidate] = true;
            $name = TypeScript::string($route->name);
            $methods = strtoupper(implode('|', $route->methods));
            $doc = str_replace('*/', '*\/', trim("{$methods} {$route->uri}"));
            $literal = TypeScriptFileCompiler::route($route, 1);

            $functions[] = <<<TS
            /** {$doc} */
            export const {$candidate} = (...[parameters]: Arguments<{$params}[{$name}]>): string =>
            	url({$name}, {$literal}, parameters);
            TS;
        }

        $body = implode("\n\n", $functions);

        return <<<TS
        import { url, type Arguments } from '{$stoli}';
        import type { {$params} } from '{$routes}';

        {$body}

        TS;
    }
}
