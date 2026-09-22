<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Constants;

use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

#[TypeScriptConstants('Boundaries')]
final class Limits
{
    public const MAX_UPLOAD = 25;

    public const RATIO = 1.5;

    public const ENABLED = true;

    public const FALLBACK = null;
}
