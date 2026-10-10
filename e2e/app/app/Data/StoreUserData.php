<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

final class StoreUserData extends Data
{
    public function __construct(
        #[MapInputName('display_name')]
        public string $name,
        public bool $admin = false,
    ) {}
}
