<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Records, for each generated file, the SHA-256 hash of the compiled content and
 * of the file left on disk after write and formatting, so that stoli:generate can
 * skip re-writing files that already hold the output.
 *
 * Entries are keyed by the path of the generated file.
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
     * $path AND the file on disk still holds the output that content produced.
     * A change made by anything other than this generator - a git checkout, a
     * merge, a hand edit - makes the file stale.
     */
    public function isUnchanged(string $path, string $content): bool
    {
        $entry = $this->load()[$path] ?? null;

        return is_array($entry)
            && ($entry['input'] ?? null) === hash('sha256', $content)
            && $this->filesystem->exists($path)
            && ($entry['output'] ?? null) === hash('sha256', $this->filesystem->get($path));
    }

    /**
     * Record the compiled $content and the state of the file left on disk at $path.
     *
     * Merges into what is on disk, so recording one file does not drop the
     * hashes recorded for its siblings.
     */
    public function record(string $path, string $content): void
    {
        $onDisk = $this->filesystem->exists($path)
            ? $this->filesystem->get($path)
            : $content;

        $this->persist([
            ...$this->load(),
            $path => [
                'input' => hash('sha256', $content),
                'output' => hash('sha256', $onDisk),
            ],
        ]);
    }

    /**
     * @return array<mixed>
     */
    private function load(): array
    {
        $path = $this->cachePath();

        if (! $this->filesystem->exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode($this->filesystem->get($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<mixed>  $data
     */
    private function persist(array $data): void
    {
        $path = $this->cachePath();

        try {
            $this->filesystem->ensureDirectoryExists(dirname($path));
            $this->filesystem->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // Cache persistence is best-effort; never block generation.
        }
    }
}
