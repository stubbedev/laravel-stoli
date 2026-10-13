<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

/**
 * The path aliases of the application's tsconfig `paths`, by which the generated files
 * import one another: a file under an aliased directory is imported through the alias
 * of the deepest one, any other file relatively.
 */
final readonly class ImportAliases
{
    /**
     * @param  array<string, string>  $directories  absolute directories by alias
     */
    private function __construct(private array $directories) {}

    /**
     * @throws StoliException when an alias or its directory is malformed
     */
    public static function fromConfig(mixed $aliases): self
    {
        if (! is_array($aliases)) {
            throw StoliException::invalidImportAliases('it must be an array of directories by alias, got '.get_debug_type($aliases));
        }

        $directories = [];

        foreach ($aliases as $alias => $directory) {
            if (! is_string($alias) || $alias === '') {
                throw StoliException::invalidImportAliases("the alias <$alias> must be a non-empty string");
            }

            if (str_ends_with($alias, '/')) {
                throw StoliException::invalidImportAliases("the alias <$alias> must not end with a slash");
            }

            if (! is_string($directory) || $directory === '') {
                throw StoliException::invalidImportAliases("the directory of the alias <$alias> must be a non-empty string");
            }

            $directories[$alias] = Utils::absolutePath($directory);
        }

        return new self($directories);
    }

    /**
     * The path a file in $fromDir imports $toFile by, without the .d.ts / .ts extension
     * TypeScript resolves by itself.
     */
    public function importPath(string $fromDir, string $toFile): string
    {
        $toFile = Utils::absolutePath($toFile);
        $module = Utils::withoutTsExtension($toFile);
        $aliased = null;
        $shortest = null;

        foreach ($this->directories as $alias => $directory) {
            $path = Utils::relativePath($directory, $module);

            if (self::isWithin($path) && ($shortest === null || strlen($path) < $shortest)) {
                $aliased = "$alias/$path";
                $shortest = strlen($path);
            }
        }

        return $aliased ?? Utils::relativeImportPath(Utils::absolutePath($fromDir), $toFile);
    }

    /**
     * Whether a path relative to a directory is of a file inside it.
     */
    private static function isWithin(string $relative): bool
    {
        return $relative !== '' && $relative !== '..' && ! str_starts_with($relative, '../');
    }
}
