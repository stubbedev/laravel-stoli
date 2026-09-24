<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

final readonly class Utils
{
    /**
     * Compute the relative import path from a directory to an absolute file path,
     * stripping .d.ts / .ts extensions (TypeScript resolves them automatically).
     */
    public static function relativeImportPath(string $fromDir, string $toFile): string
    {
        $toFile = (string) preg_replace('/\.d\.ts$|\.ts$/', '', $toFile);

        $from = array_values(array_filter(explode('/', $fromDir), static fn (string $p): bool => $p !== ''));
        $to = array_values(array_filter(explode('/', $toFile), static fn (string $p): bool => $p !== ''));

        $common = 0;
        $max = min(count($from), count($to));

        while ($common < $max && $from[$common] === $to[$common]) {
            $common++;
        }

        $parts = [...array_fill(0, count($from) - $common, '..'), ...array_slice($to, $common)];
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
