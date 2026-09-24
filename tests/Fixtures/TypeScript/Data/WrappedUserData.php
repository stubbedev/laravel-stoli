<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data;

use Spatie\LaravelData\Data;

/**
 * Wraps itself in a response, whatever the data.wrap config says.
 */
final class WrappedUserData extends Data
{
    public function __construct(
        public int $id,
    ) {}

    public function defaultWrap(): string
    {
        return 'user';
    }
}
