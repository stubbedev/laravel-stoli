<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

final readonly class Publisher
{
    public function __construct(
        private Generator $generator,
        private GeneratedFileWriter $writer,
    ) {}

    /**
     * Write every generated file, formatting them together at the end, and remove the
     * files an earlier run generated that this one does not.
     */
    public function publish(): void
    {
        $generation = $this->generator->generate();

        $this->writer->batch(function () use ($generation): void {
            foreach ($generation->files as $file) {
                $this->writer->write($file);
            }

            foreach ($generation->removals as $removal) {
                $this->writer->remove($removal);
            }
        });
    }

    /**
     * The files that are not what a run would leave behind: missing, changed, or left
     * over from an earlier run.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        return $this->writer->stale($this->generator->generate());
    }
}
