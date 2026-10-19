<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Constants;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Transformed to a union type only: without this package's attribute its
 * cases are not published as values.
 */
#[TypeScript]
enum Phase: string
{
    case Early = 'early';
    case Late = 'late';
}
