<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Support;

use InvalidArgumentException;

use function sprintf;

final class InvalidSafelistItem extends InvalidArgumentException
{
    public function __construct(string $collection, string $expected, string $given)
    {
        parent::__construct(
            sprintf('The collection <%s> requires type <%s>, but <%s> was given', $collection, $expected, $given)
        );
    }
}
