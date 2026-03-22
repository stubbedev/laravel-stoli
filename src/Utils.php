<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use function json_encode;
use function preg_replace;
use function preg_replace_callback;
use function str_repeat;

final readonly class Utils
{
    public static function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return preg_replace_callback('/^( {4})+/m', fn($m) => str_repeat("\t", strlen($m[0]) / 4), $json);
    }

    public static function removeForwardSlashes(?string $fragment): string
    {
        if ($fragment === null) {
            return '';
        }

        return preg_replace('/(^\/?)|(\/?$)/', '', $fragment);
    }
}
