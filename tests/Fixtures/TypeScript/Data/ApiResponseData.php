<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data;

use Spatie\LaravelData\Data;

/**
 * @template TData
 */
final class ApiResponseData extends Data
{
    /**
     * @param  TData  $data
     */
    public function __construct(
        public mixed $data,
    ) {}
}
