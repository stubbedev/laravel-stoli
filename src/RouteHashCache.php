<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Items\File;

/**
 * Records, for each generated route file, the SHA-256 hash of the compiled
 * content and of the file left on disk after write and formatting, so that
 * stoli:generate can skip re-writing files that already hold the output.
 *
 * Cache file location: <vendor/stubbedev/laravel-stoli>/.cache
 * Stored inside the package directory so it is invisible to the application
 * and automatically excluded by the consuming app's .gitignore (vendor/).
 */
final readonly class RouteHashCache
{
    public function __construct(private Filesystem $filesystem) {}

    private function cachePath(): string
    {
        // __DIR__ is src/ — one level up is the package root inside vendor/
        return dirname(__DIR__).'/.cache';
    }

    /**
     * Returns true when $content is identical to the content last compiled for
     * this file AND the file on disk still holds the output that content
     * produced. A change made by anything other than this generator - a git
     * checkout, a merge, a hand edit - makes the file stale.
     */
    public function isUnchanged(File $file, string $content, string $writtenPath): bool
    {
        $entry = $this->load()[$this->key($file)] ?? null;

        return is_array($entry)
            && isset($entry['input'], $entry['output'])
            && $entry['input'] === hash('sha256', $content)
            && $this->filesystem->exists($writtenPath)
            && $entry['output'] === hash('sha256', $this->filesystem->get($writtenPath));
    }

    /**
     * Record the compiled $content and the state of the file left on disk for $file.
     *
     * Merges into what is on disk, so recording one file does not drop the
     * hashes recorded for its siblings.
     */
    public function record(File $file, string $content, string $writtenPath): void
    {
        $onDisk = $this->filesystem->exists($writtenPath)
            ? $this->filesystem->get($writtenPath)
            : $content;

        $this->persist(array_merge($this->load(), [
            $this->key($file) => [
                'input' => hash('sha256', $content),
                'output' => hash('sha256', $onDisk),
            ],
        ]));
    }

    private function key(File $file): string
    {
        return $file->path().'/'.$file->name();
    }

    private function load(): array
    {
        $path = $this->cachePath();

        if (! $this->filesystem->exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode($this->filesystem->get($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function persist(array $data): void
    {
        $path = $this->cachePath();

        try {
            $this->filesystem->makeDirectory(dirname($path), 0755, true, true);
            $this->filesystem->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Cache persistence is best-effort; never block generation.
        }
    }
}
