<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Container\Container;
use ReflectionProperty;
use Spatie\TypeScriptTransformer\Formatters\Formatter;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Throwable;

use function get_object_vars;

/**
 * What Stoli reads from the spatie/typescript-transformer setup: the directory it
 * writes to, the file holding the transformed types, the formatter it runs and the
 * directories it discovers classes in.
 *
 * Everything is null or empty when the transformer is not bound in the container,
 * so the exporters that depend on it write nothing instead of failing.
 */
final readonly class TransformerOutput
{
    public const BINDING = 'Spatie\\TypeScriptTransformer\\TypeScriptTransformerConfig';

    /**
     * @param  list<string>  $directoriesToWatch
     */
    public function __construct(
        public ?string $directory = null,
        public ?string $typesFile = null,
        public ?Formatter $formatter = null,
        public array $directoriesToWatch = [],
    ) {}

    public static function fromContainer(Container $container): self
    {
        try {
            $config = $container->make(self::BINDING);
        } catch (Throwable) {
            return new self;
        }

        // Read as public properties so stand-ins that only carry some of them work too.
        $properties = get_object_vars($config);

        $outputDirectory = is_string($properties['outputDirectory'] ?? null)
            ? rtrim($properties['outputDirectory'], '/\\')
            : null;

        [$directory, $typesFile] = self::locate($outputDirectory, self::writerPath($properties['typesWriter'] ?? null));

        $formatter = $properties['formatter'] ?? null;
        $watched = $properties['directoriesToWatch'] ?? null;

        return new self(
            directory: $directory,
            typesFile: $typesFile,
            formatter: $formatter instanceof Formatter ? $formatter : null,
            directoriesToWatch: is_array($watched)
                ? array_values(array_unique(array_filter($watched, is_string(...))))
                : [],
        );
    }

    /**
     * The GlobalNamespaceWriter keeps the file it writes in a protected property; it
     * is the only writer that names a single types file.
     */
    private static function writerPath(mixed $writer): ?string
    {
        if (! $writer instanceof GlobalNamespaceWriter) {
            return null;
        }

        try {
            $path = (new ReflectionProperty($writer, 'path'))->getValue($writer);
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @return array{?string, ?string} the output directory and the types file
     */
    private static function locate(?string $outputDirectory, ?string $writerPath): array
    {
        if ($writerPath === null) {
            return [
                $outputDirectory,
                $outputDirectory === null ? null : $outputDirectory.DIRECTORY_SEPARATOR.'index.d.ts',
            ];
        }

        $writerDirectory = dirname($writerPath);

        // The writer usually holds a bare filename, relative to the output directory.
        if (! Utils::isAbsolutePath($writerPath) && $outputDirectory !== null) {
            return [
                $outputDirectory.($writerDirectory !== '.' ? DIRECTORY_SEPARATOR.$writerDirectory : ''),
                $outputDirectory.DIRECTORY_SEPARATOR.$writerPath,
            ];
        }

        return [$writerDirectory !== '.' ? $writerDirectory : $outputDirectory, $writerPath];
    }
}
