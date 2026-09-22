<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Constants;

use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

/**
 * Enums are transformed by spatie/typescript-transformer, so the discoverer
 * must leave them alone even when they carry the attribute.
 */
#[TypeScriptConstants]
enum Status: string
{
    case Draft = 'draft';
    case Live = 'live';
}
