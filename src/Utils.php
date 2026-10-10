<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

final readonly class Utils
{
    /**
     * The extensions TypeScript resolves an import to by itself, longest first.
     */
    private const TS_EXTENSIONS = ['.d.ts', '.ts'];

    /**
     * Compute the relative import path from a directory to an absolute file path,
     * stripping .d.ts / .ts extensions (TypeScript resolves them automatically).
     *
     * Either separator is accepted, so Windows paths work too; the import path always
     * uses forward slashes, as TypeScript expects.
     */
    public static function relativeImportPath(string $fromDir, string $toFile): string
    {
        foreach (self::TS_EXTENSIONS as $extension) {
            if (str_ends_with($toFile, $extension)) {
                $toFile = substr($toFile, 0, -strlen($extension));

                break;
            }
        }

        $rel = self::relativePath($fromDir, $toFile);

        if ($rel === '') {
            return '.';
        }

        return str_starts_with($rel, '.') ? $rel : './'.$rel;
    }

    /**
     * The path of $toFile relative to $fromDir, with forward slashes.
     */
    public static function relativePath(string $fromDir, string $toFile): string
    {
        $from = self::segments($fromDir);
        $to = self::segments($toFile);

        $common = 0;
        $max = min(count($from), count($to));

        while ($common < $max && self::sameSegment($from[$common], $to[$common], $common)) {
            $common++;
        }

        return implode('/', [...array_fill(0, count($from) - $common, '..'), ...array_slice($to, $common)]);
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
            || (self::isDrive(substr($path, 0, 2)) && in_array(substr($path, 2, 1), ['/', '\\'], true));
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * Windows drive letters compare without regard to case; every other segment exactly.
     */
    private static function sameSegment(string $a, string $b, int $index): bool
    {
        return $index === 0 && self::isDrive($a) ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /**
     * Whether a path segment is a Windows drive, such as `C:`.
     */
    private static function isDrive(string $segment): bool
    {
        return strlen($segment) === 2 && ctype_alpha($segment[0]) && $segment[1] === ':';
    }
}
