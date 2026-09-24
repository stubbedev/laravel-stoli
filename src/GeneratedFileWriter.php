<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Writes a generated file unless it already holds what the same content produced on
 * the last run, so an unchanged file keeps its mtime and does not wake file watchers.
 *
 * Inside batch(), formatting is deferred: the formatter is started once for every
 * file written in the batch rather than once per file, which matters for a formatter
 * such as Prettier that runs as a process of its own.
 */
final class GeneratedFileWriter
{
    /**
     * Files written in the current batch that still need formatting, path => content.
     *
     * @var array<string, string>|null
     */
    private ?array $pending = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly RouteHashCache $hashCache,
        private readonly TransformerOutput $output,
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

        if (! $format || $this->output->formatter === null) {
            $this->hashCache->record($path, $content);

            return;
        }

        if ($this->pending !== null) {
            $this->pending[$path] = $content;

            return;
        }

        $this->format([$path => $content]);
    }

    /**
     * Run $callback with formatting deferred to its end.
     *
     * When the callback fails, the files it wrote are left unformatted and unrecorded,
     * so the next run writes them again.
     *
     * @param  callable(): void  $callback
     */
    public function batch(callable $callback): void
    {
        if ($this->pending !== null) {
            $callback();

            return;
        }

        $this->pending = [];

        try {
            $callback();
            $pending = $this->pending;
        } finally {
            $this->pending = null;
        }

        $this->format($pending);
    }

    /**
     * Format the files, then record the formatted result, which is what the next run
     * compares the file on disk against.
     *
     * @param  array<string, string>  $files  path => the content written there
     */
    private function format(array $files): void
    {
        if ($files === []) {
            return;
        }

        try {
            $this->output->formatter?->format(array_keys($files));
        } catch (Throwable $error) {
            throw StoliException::cantFormat($error);
        }

        foreach ($files as $path => $content) {
            $this->hashCache->record($path, $content);
        }
    }
}
