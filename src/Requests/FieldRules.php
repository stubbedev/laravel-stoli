<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Requests;

use StubbeDev\LaravelStoli\Items\DataType;

/**
 * What the validation rules of one field say about the input it takes.
 */
final readonly class FieldRules
{
    /**
     * @param  DataType|null  $type  the type the rules allow; null when they do not say
     * @param  bool  $narrowing  whether $type is narrower than any type a field's own declaration gives it
     * @param  bool|null  $required  whether it must be present; null when the rules do not say
     * @param  bool  $nullable  whether it may be null
     * @param  bool  $notNull  whether it may not be null even where its declaration allows it: `required` and `filled` fail on null
     * @param  bool  $prohibited  whether it may not be sent at all
     * @param  bool  $list  whether it is an array
     * @param  string|null  $confirmation  the field that has to repeat it
     */
    public function __construct(
        public ?DataType $type = null,
        public bool $narrowing = false,
        public ?bool $required = null,
        public bool $nullable = false,
        public bool $notNull = false,
        public bool $prohibited = false,
        public bool $list = false,
        public ?string $confirmation = null,
    ) {}
}
