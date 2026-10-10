<?php

declare(strict_types=1);

// Set up from the environment, so each e2e scenario picks what it generates.
return [
    // env() reads 'true' and 'false' as booleans.
    'split' => env('E2E_SPLIT', true) === true,
    'transform' => env('E2E_MODE', 'transform') === 'transform',
    'client' => env('E2E_CLIENT', 'fetch') === 'none' ? null : env('E2E_CLIENT', 'fetch'),
    'urls' => env('E2E_URLS', true) === true,
    'constants' => ['enabled' => true, 'attributes' => [StubbeDev\LaravelStoli\Attributes\TypeScriptConstants::class]],
    'single' => ['name' => 'api'],
    'modules' => [
        ['match' => '/api', 'name' => 'api', 'rootUrl' => env('APP_URL')],
        ['match' => '*', 'name' => 'pages', 'names' => 'pages.*', 'standalone' => true, 'absolute' => false, 'path' => resource_path('js/generated')],
    ],
];
