<?php

declare(strict_types=1);

/*
 * Wires the e2e overlay into the fresh Laravel skeleton: the api routes, and the
 * typescript-transformer provider. Fails loudly when the skeleton looks different.
 */

function patch(string $file, string $pattern, string $replacement, string $marker): void
{
    $source = (string) file_get_contents($file);

    if (str_contains($source, $marker)) {
        return;
    }

    $patched = preg_replace($pattern, $replacement, $source, 1, $count);

    if ($count !== 1 || ! is_string($patched)) {
        fwrite(STDERR, "Could not patch {$file}:\n{$source}\n");
        exit(1);
    }

    file_put_contents($file, $patched);
}

patch(
    'bootstrap/app.php',
    '/(web:\s*__DIR__\s*\.\s*[\'"]\/\.\.\/routes\/web\.php[\'"],)/',
    "$1\n        api: __DIR__.'/../routes/api.php',",
    'routes/api.php',
);

patch(
    'bootstrap/providers.php',
    '/((?:App\\\\Providers\\\\)?AppServiceProvider::class,)/',
    "$1\n    App\\Providers\\TypeScriptTransformerServiceProvider::class,",
    'TypeScriptTransformerServiceProvider',
);
