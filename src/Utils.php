<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use function json_encode;
use function preg_replace;
use function preg_replace_callback;
use function str_repeat;

final readonly class Utils
{
    public static function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return preg_replace_callback('/^( {4})+/m', fn ($m) => str_repeat("\t", strlen($m[0]) / 4), $json);
    }

    public static function removeForwardSlashes(?string $fragment): string
    {
        if ($fragment === null) {
            return '';
        }

        return preg_replace('/(^\/?)|(\/?$)/', '', $fragment);
    }

    /**
     * Compute the relative import path from a directory to an absolute file path,
     * stripping .d.ts / .ts extensions (TypeScript resolves them automatically).
     */
    public static function relativeImportPath(string $fromDir, string $toFile): string
    {
        // Strip TS extensions — TypeScript resolves the file without them
        $toFile = preg_replace('/\.d\.ts$|\.ts$/', '', $toFile);

        $from = array_values(array_filter(explode('/', $fromDir), fn ($p) => $p !== ''));
        $to = array_values(array_filter(explode('/', $toFile), fn ($p) => $p !== ''));

        $common = 0;
        $max = min(count($from), count($to));

        while ($common < $max && $from[$common] === $to[$common]) {
            $common++;
        }

        $ups = count($from) - $common;
        $downs = array_slice($to, $common);
        $parts = [...array_fill(0, $ups, '..'), ...$downs];
        $rel = implode('/', $parts);

        if ($rel === '') {
            return '.';
        }

        return str_starts_with($rel, '.') ? $rel : './'.$rel;
    }

    /**
     * Resolve a configured path against the application root unless it is already absolute.
     */
    public static function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
