<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Attributes;

use Attribute;

/**
 * Marks a class whose public constants should be exported to the generated
 * TypeScript constants file.
 *
 * Classes carrying spatie/typescript-transformer's #[TypeScript] attribute are
 * picked up as well — see the `constants.attributes` key in config/stoli.php.
 *
 * The optional $name overrides the key the constants are published under; by
 * default the class basename is used.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypeScriptConstants
{
    public function __construct(
        public ?string $name = null,
    ) {}
}
