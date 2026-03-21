<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Compilers;

use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Route;
use StubbeDev\LaravelStoli\Utils;

final readonly class JsonFileCompiler implements Compiler
{
    public function compile(File $file): string
    {
        return Utils::jsonEncode($file->routes()->reduce(self::serialize(...), []));
    }

    public function extension(): string
    {
        return 'json';
    }

    private static function serialize(array $acc, Route $route): array
    {
        return [
            ...$acc,
            $route->name() => $route->primitives(),
        ];
    }
}
