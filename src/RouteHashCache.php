<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Filesystem\Filesystem;
use StubbeDev\LaravelStoli\Items\File;

use function Illuminate\Filesystem\join_paths;

/**
 * Stores a SHA-256 hash of each compiled route file's content so that
 * stoli:generate can skip re-writing files that have not changed.
 *
 * Cache file location: <vendor/stubbedev/laravel-stoli>/.cache
 * Stored inside the package directory so it is invisible to the application
 * and automatically excluded by the consuming app's .gitignore (vendor/).
 */
final readonly class RouteHashCache
{
    private array $stored;

    public function __construct(private Filesystem $filesystem)
    {
        $this->stored = $this->load();
    }

    private function cachePath(): string
    {
        // __DIR__ is src/ — one level up is the package root inside vendor/
        return dirname(__DIR__).'/.cache';
    }

    /**
     * Returns true if $content is identical to the last written content for
     * this file AND the file still exists on disk.
     */
    public function isUnchanged(File $file, string $content): bool
    {
        $key = $this->key($file);
        $hash = hash('sha256', $content);
        $onDisk = join_paths($file->path(), $file->name().'.ts');

        return isset($this->stored[$key])
            && $this->stored[$key] === $hash
            && $this->filesystem->exists($onDisk);
    }

    /**
     * Record the hash of content that was just written for $file.
     */
    public function record(File $file, string $content): void
    {
        $key = $this->key($file);
        $hash = hash('sha256', $content);
        $fresh = array_merge($this->stored, [$key => $hash]);

        $this->persist($fresh);
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
