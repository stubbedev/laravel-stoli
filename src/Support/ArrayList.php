<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Support;

use function array_unshift;

class ArrayList extends AbstractList
{
    public static function from(iterable $items): static
    {
        return new static($items);
    }

    public function prepend(mixed $item, string|int|null $key = null): static
    {
        if ($key === null) {
            array_unshift($this->items, $item);
        } else {
            $this->items = [$key => $item] + $this->items;
        }

        return $this;
    }

    public function push(mixed $item, string|int|null $key = null): static
    {
        if ($key === null) {
            $this->items[] = $item;
        } else {
            $this->items[$key] = $item;
        }

        return $this;
    }
}
