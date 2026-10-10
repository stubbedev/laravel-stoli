<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class LineData extends Data
{
    public function __construct(
        public string $sku,
        public int $quantity,
    ) {}
}
