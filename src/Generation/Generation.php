<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Generation;

/**
 * What a run generates: the files to write, and the files to remove. A path written is
 * never also removed.
 */
final readonly class Generation
{
    /** @var list<GeneratedFile> */
    public array $files;

    /** @var list<Removal> */
    public array $removals;

    /**
     * @param  list<GeneratedFile>  $files
     * @param  list<Removal>  $removals
     */
    public function __construct(array $files = [], array $removals = [])
    {
        $written = [];

        foreach ($files as $file) {
            $written[$file->path] = $file;
        }

        $this->files = array_values($written);
        $this->removals = array_values(array_filter(
            $removals,
            static fn (Removal $removal): bool => ! isset($written[$removal->path]),
        ));
    }

    public function with(self ...$others): self
    {
        $files = $this->files;
        $removals = $this->removals;

        foreach ($others as $other) {
            $files = [...$files, ...$other->files];
            $removals = [...$removals, ...$other->removals];
        }

        return new self($files, $removals);
    }
}
