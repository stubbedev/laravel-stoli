<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class CategoryData extends Data
{
    /**
     * @param  array<int, CategoryData>  $children
     */
    public function __construct(
        public string $name,
        #[DataCollectionOf(CategoryData::class)]
        public array $children = [],
        public ?CategoryData $parent = null,
    ) {}
}
