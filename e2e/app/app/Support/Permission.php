<?php

declare(strict_types=1);

namespace App\Support;

use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

#[TypeScriptConstants]
final class Permission
{
    public const VIEW = 'view';

    public const EDIT = 'edit';
}
