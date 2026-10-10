<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data;

enum Status: string
{
    case Active = 'active';
    case Archived = 'archived';
}
