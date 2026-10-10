<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Transformer;

use Illuminate\Contracts\Container\Container;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Data\GlobalNamespaceResolvedReference;
use Spatie\TypeScriptTransformer\Data\WriteableFile;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Writers\Writer;
use StubbeDev\LaravelStoli\GeneratedFileWriter;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generator;
use StubbeDev\LaravelStoli\TransformedTypes;
use StubbeDev\LaravelStoli\TransformerOutput;
use StubbeDev\LaravelStoli\Utils;

/**
 * The writer of Stoli's place in the transformed collection. When the transformer writes
 * its files, it hands this writer the whole collection, so Stoli's files are generated
 * from exactly the types being written, in the same run, and written, formatted and
 * cleaned up by the transformer along with them.
 */
final readonly class StoliWriter implements Writer
{
    public function __construct(private Container $container) {}

    public function output(array $transformed, TransformedCollection $transformedCollection): array
    {
        $this->container->make(TransformedTypes::class)->use($transformedCollection);

        $generation = $this->container->make(Generator::class)->generate();
        $writer = $this->container->make(GeneratedFileWriter::class);

        foreach ($generation->removals as $removal) {
            $writer->remove($removal);
        }

        $directory = (string) $this->container->make(TransformerOutput::class)->directory;
        $formatted = [];

        // The transformer formats every file it writes, so the ones that are not to be
        // formatted, the route service modules, are written here instead.
        foreach ($generation->files as $file) {
            if ($file->format) {
                $formatted[] = new WriteableFile(Utils::relativePath($directory, $file->path), $file->contents);
            } else {
                $writer->write($file);
            }
        }

        return $formatted;
    }

    public function resolveReference(Transformed $transformed): GlobalNamespaceResolvedReference
    {
        // Nothing references Stoli's place in the collection.
        return new GlobalNamespaceResolvedReference('');
    }
}
