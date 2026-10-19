<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Constants;

use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

/**
 * An enum that asks for its cases as values on top of the union type
 * spatie/typescript-transformer gives it.
 */
#[TypeScriptConstants]
enum Status: string
{
    case Draft = 'draft';
    case Live = 'live';
}
