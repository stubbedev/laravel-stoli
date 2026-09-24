<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Filesystem\Filesystem;

/**
 * Writes a generated file unless it already holds what the same content produced on
 * the last run, so an unchanged file keeps its mtime and does not wake file watchers.
 */
final readonly class GeneratedFileWriter
{
    public function __construct(
        private Filesystem $filesystem,
        private RouteHashCache $hashCache,
        private TransformerOutput $output,
    ) {}

    /**
     * @param  bool  $format  run the typescript-transformer formatter over the written file
     */
    public function write(string $path, string $content, bool $format = true): void
    {
        $path = Utils::absolutePath($path);

        if ($this->hashCache->isUnchanged($path, $content)) {
            return;
        }

        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $content);

        if ($format) {
            $this->output->formatter?->format([$path]);
        }

        $this->hashCache->record($path, $content);
    }
}
