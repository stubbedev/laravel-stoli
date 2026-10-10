<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Requests;

/**
 * A field in a tree of validation rules keyed by field paths: `address.street` is a
 * child of `address`, `items.*` the item of the list `items`.
 */
final class RuleNode
{
    /** @var list<mixed> */
    public array $rules = [];

    /** @var array<string, self> */
    public array $children = [];

    public ?self $item = null;
}
