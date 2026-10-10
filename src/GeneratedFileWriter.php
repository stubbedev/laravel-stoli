<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Generation\GeneratedFile;
use StubbeDev\LaravelStoli\Generation\Generation;
use StubbeDev\LaravelStoli\Generation\Removal;
use Throwable;

/**
 * Writes generated files, and removes the ones an earlier run generated. A file is not rewritten when it already holds what the same content produced on the
 * last run, so an unchanged file keeps its mtime and does not wake file watchers.
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

    public function write(GeneratedFile $file): void
    {
        $path = $file->path;
        $content = $file->contents;

        if ($this->hashCache->isUnchanged($path, $content)) {
            return;
        }

        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $content);

        if (! $file->format || $this->output->formatter === null) {
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
     * Remove a file an earlier run generated and this one does not. A file Stoli did not
     * generate is left alone.
     */
    public function remove(Removal $removal): void
    {
        if ($this->removable($removal)) {
            $this->filesystem->delete($removal->path);
        }
    }

    /**
     * The paths that do not hold what writing $generation would leave there: files that
     * are missing or differ, and files it would remove.
     *
     * A file that is formatted is compared with what the formatter makes of it, which is
     * found by formatting a copy next to it, so the formatter picks up the same config.
     *
     * @return list<string>
     */
    public function stale(Generation $generation): array
    {
        $stale = [];
        $copies = [];

        foreach ($generation->files as $file) {
            if (! $this->filesystem->isFile($file->path)) {
                $stale[] = $file->path;
            } elseif ($file->format && $this->output->formatter !== null) {
                $copy = dirname($file->path).'/.stoli-check.'.basename($file->path);
                $this->filesystem->put($copy, $file->contents);
                $copies[$copy] = $file->path;
            } elseif ($this->filesystem->get($file->path) !== $file->contents) {
                $stale[] = $file->path;
            }
        }

        try {
            if ($copies !== []) {
                $this->output->formatter?->format(array_keys($copies));
            }

            foreach ($copies as $copy => $path) {
                if ($this->filesystem->get($copy) !== $this->filesystem->get($path)) {
                    $stale[] = $path;
                }
            }
        } catch (Throwable $error) {
            throw StoliException::cantFormat($error);
        } finally {
            $this->filesystem->delete(array_keys($copies));
        }

        foreach ($generation->removals as $removal) {
            if ($this->removable($removal)) {
                $stale[] = $removal->path;
            }
        }

        sort($stale);

        return $stale;
    }

    private function removable(Removal $removal): bool
    {
        return $this->filesystem->isFile($removal->path)
            && GeneratedFile::isGenerated($this->filesystem->get($removal->path), $removal->legacy);
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
