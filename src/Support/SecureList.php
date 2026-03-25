<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Support;

abstract class SecureList extends AbstractList
{
    use ValueStringify;

    public function __construct(iterable $items)
    {
        $this->ensureTypesOf($items);
        parent::__construct($items);
    }

    abstract public static function type(): string;

    public function merge(AbstractList $list): static
    {
        static::ensureTypesOf($list->items());

        return parent::merge($list);
    }

    public function overlay(AbstractList $list): static
    {
        static::ensureTypesOf($list->items());

        return parent::overlay($list);
    }

    protected function ensureTypesOf(iterable $items): void
    {
        $type = static::type();
        $isNative = in_array($type, static::natives());

        foreach ($items as $item) {
            $valid = $isNative
                ? gettype($item) === $type
                : $item instanceof $type;

            if (! $valid) {
                throw new InvalidSafelistItem(static::class, $type, $this->valueToString($item));
            }
        }
    }

    protected static function natives(): array
    {
        return ['boolean', 'integer', 'double', 'string', 'array', 'object', 'resource'];
    }
}
