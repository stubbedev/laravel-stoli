<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Constants;

use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

#[TypeScriptConstants]
final class Permission
{
    public const VIEW = 'view';

    public const EDIT = 'edit';

    public const LEVELS = ['low' => 1, 'high' => 2];

    protected const HIDDEN = 'protected';

    private const SECRET = 'private';

    public function secret(): string
    {
        return self::SECRET;
    }
}
