<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

trait RuntimeModule
{
    public function module(): string
    {
        return "stoli-{$this->value}";
    }
}
