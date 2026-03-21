<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function array_filter;
use function array_merge;
use function array_reverse;
use function array_unique;
use function array_values;
use function count;
use function iterator_to_array;

abstract class AbstractList implements Countable, IteratorAggregate
{
    public const Empty = [];

    protected array $items;

    public function __construct(iterable $items)
    {
        $this->items = $this->safelyProcessItems($items);
    }

    public function merge(self $list): static
    {
        return new static([
            ...$this->values(),
            ...$list->values(),
        ]);
    }

    public function overlay(self $list): static
    {
        $items = $this->items();

        $list->each(function (mixed $item, int|string $key) use (&$items) {
            $items[$key] = $item;
        });

        return new static($items);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    public function flatMap(callable $mapper): ArrayList
    {
        $result = [];

        foreach ($this->items() as $key => $value) {
            $mapped = $mapper($value, $key);

            if (is_array($mapped)) {
                $result = array_merge($result, array_values($mapped));
            } elseif ($mapped instanceof self) {
                $result = array_merge($result, $mapped->values());
            } else {
                $result[] = $mapped;
            }
        }

        return new ArrayList($result);
    }

    public function map(callable $mapper): ArrayList
    {
        $result = [];

        foreach ($this->items() as $key => $value) {
            $result[] = $mapper($value, $key);
        }

        return new ArrayList($result);
    }

    public function pick(callable $predicate): mixed
    {
        foreach ($this->items() as $key => $value) {
            if ($predicate($value, $key)) {
                return $value;
            }
        }

        return null;
    }

    public function each(callable $action): static
    {
        foreach ($this->items() as $key => $value) {
            $action($value, $key);
        }

        return $this;
    }

    public function filter(callable $predicate): static
    {
        return new static(array_filter($this->items(), $predicate, ARRAY_FILTER_USE_BOTH));
    }

    public function reduce(callable $accumulator, mixed $initial = null): mixed
    {
        $result = $initial;

        foreach ($this->items() as $key => $value) {
            $result = $accumulator($result, $value, $key);
        }

        return $result;
    }

    public function some(callable $predicate): bool
    {
        foreach ($this->items() as $key => $value) {
            if ($predicate($value, $key)) {
                return true;
            }
        }

        return false;
    }

    public function keyOf(callable $predicate): int|string|null
    {
        foreach ($this->items() as $key => $item) {
            if ($predicate($item, $key)) {
                return $key;
            }
        }

        return null;
    }

    public function unique(callable $mapper = null): static
    {
        if (null === $mapper) {
            return new static(array_unique($this->items(), SORT_REGULAR));
        }

        $exists = [];

        return $this->filter(static function (mixed $item, int|string $key) use ($mapper, &$exists): bool {
            if (in_array($value = $mapper($item, $key), $exists, true)) {
                return false;
            }

            $exists[] = $value;
            return true;
        });
    }

    public function duplicates(callable $mapper = null): ArrayList
    {
        $items   = null === $mapper ? new ArrayList($this) : $this->map($mapper);
        $uniques = $items->unique()->items();

        return $items->filter(
            static fn(mixed $element, int|string $key) => !isset($uniques[$key]) || $uniques[$key] != $element
        );
    }

    public function items(): array
    {
        return $this->items;
    }

    public function values(): array
    {
        return array_values($this->items());
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items());
    }

    protected function safelyProcessItems(iterable $items): array
    {
        return self::parse($items);
    }

    protected static function parse(iterable $items): array
    {
        return match (true) {
            $items instanceof self => $items->items(),
            $items instanceof Traversable => iterator_to_array($items),
            $items instanceof ArrayIterator => $items->getArrayCopy(),
            default => $items,
        };
    }
}
