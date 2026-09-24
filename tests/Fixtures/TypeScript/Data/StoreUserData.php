<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data;

use Spatie\LaravelData\Data;

final class StoreUserData extends Data
{
    public function __construct(
        public string $name,
        public bool $admin = false,
    ) {}
}
