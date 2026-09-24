<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\InvalidConstants;

use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

/**
 * Kept apart from the other fixtures: discovering it fails the whole scan.
 */
#[TypeScriptConstants(name: 'bad-name')]
final class BadName
{
    public const VALUE = 'value';
}
