<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Generation;

use StubbeDev\LaravelStoli\Utils;

/**
 * A file an earlier run may have generated that this one does not. It is removed only
 * when it was generated: see GeneratedFile::isGenerated().
 */
final readonly class Removal
{
    public string $path;

    /**
     * @param  string|null  $legacy  what a version before the header started the file with
     */
    public function __construct(string $path, public ?string $legacy = null)
    {
        $this->path = Utils::absolutePath($path);
    }
}
