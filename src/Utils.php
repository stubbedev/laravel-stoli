<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

final readonly class Utils
{
    /**
     * Compute the relative import path from a directory to an absolute file path,
     * stripping .d.ts / .ts extensions (TypeScript resolves them automatically).
     *
     * Either separator is accepted, so Windows paths work too; the import path always
     * uses forward slashes, as TypeScript expects.
     */
    public static function relativeImportPath(string $fromDir, string $toFile): string
    {
        $toFile = (string) preg_replace('/\.d\.ts$|\.ts$/', '', $toFile);

        $from = self::segments($fromDir);
        $to = self::segments($toFile);

        $common = 0;
        $max = min(count($from), count($to));

        while ($common < $max && self::sameSegment($from[$common], $to[$common], $common)) {
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
        return self::isAbsolutePath($path) ? $path : base_path($path);
    }

    /**
     * Whether a path is absolute: `/srv/app`, or on Windows `C:\app`, `C:/app` or `\\server\share`.
     */
    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(
            preg_split('#[\\\\/]+#', $path) ?: [],
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * Windows drive letters compare without regard to case; every other segment exactly.
     */
    private static function sameSegment(string $a, string $b, int $index): bool
    {
        return $index === 0 && preg_match('/^[A-Za-z]:$/', $a) === 1
            ? strcasecmp($a, $b) === 0
            : $a === $b;
    }
}
