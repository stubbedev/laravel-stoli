<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Split Routes into Multiple Files
    |--------------------------------------------------------------------------
    |
    | This option controls whether the routes for each module should be
    | generated into separate files, or if all routes should be
    | combined into a single file.
    |
    | true  - Creates a separate TypeScript file for each module. The file name
    |         will be the same as the module's name (e.g., 'store.ts', 'admin.ts').
    |
    | false - Combines all routes from all modules into a single file
    |         named using the 'single.name' option below.
    |
    */
    'split' => true,

    /*
    |--------------------------------------------------------------------------
    | Axios Router
    |--------------------------------------------------------------------------
    |
    | When enabled, a typed router.ts file is generated alongside your route
    | files. It wraps axios with the Stoli route service, providing a single
    | import for all HTTP calls with full route name and parameter autocompletion.
    |
    | Requires axios to be installed: npm install axios
    |
    */
    'axios' => false,

    /*
    |--------------------------------------------------------------------------
    | Single Output Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration block defines the settings for the unified routes
    | file. These settings are exclusively used when the 'split' option
    | is set to false.
    |
    | name - The name of the generated file (without extension). Defaults
    |        to 'api' if not specified.
    |
    */
    'single' => [
        'name' => 'api',
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules Configuration
    |--------------------------------------------------------------------------
    |
    | Define the configuration for each module. Each module has its own set of
    | routes and settings for generating the corresponding TypeScript routes file.
    |
    */
    'modules' => [
        [
            /*
            |--------------------------------------------------------------------------
            | Route Matching Criteria
            |--------------------------------------------------------------------------
            |
            | Specify which routes should be included. Use '*' to include all routes,
            | or provide a specific path to match (e.g., '/api') to filter routes.
            |
            */
            'match' => '*',

            /*
            |--------------------------------------------------------------------------
            | Module Name
            |--------------------------------------------------------------------------
            |
            | The name of the module and the resulting TypeScript file.
            |
            */
            'name' => 'api',

            /*
            |--------------------------------------------------------------------------
            | Root URL
            |--------------------------------------------------------------------------
            |
            | This URL is used as the base for generating absolute URLs when 'absolute'
            | is set to true. If 'absolute' is true, the value of 'rootUrl' will be used.
            | If 'rootUrl' is not defined, the value will fall back to the APP_URL environment
            | variable defined in your .env file. If neither is set, URLs will not be absolute.
            |
            */
            'rootUrl' => env('APP_URL', 'http://localhost'),

            /*
            |--------------------------------------------------------------------------
            | Absolute URLs
            |--------------------------------------------------------------------------
            |
            | If set to true, absolute URLs will be generated. The base URL used for these
            | absolute URLs is determined by the 'rootUrl' setting. If 'rootUrl' is not set,
            | the value of the APP_URL environment variable will be used. If 'absolute' is
            | false, relative URLs will be generated.
            |
            */
            'absolute' => true,

            /*
            |--------------------------------------------------------------------------
            | URL Prefix
            |--------------------------------------------------------------------------
            |
            | Here you may specify a prefix that will be added to all generated URLs.
            | By default, this value is null, meaning no prefix will be added.
            |
            */
            'prefix' => null,

            /*
            |--------------------------------------------------------------------------
            | Destination Path
            |--------------------------------------------------------------------------
            |
            | This value determines the path where the generated TypeScript routes file
            | will be stored. If omitted, it falls back to the typescript-transformer
            | output directory.
            |
            */
            'path' => null,

            /*
            |--------------------------------------------------------------------------
            | Strip Route Name Prefix
            |--------------------------------------------------------------------------
            |
            | When set, this prefix is stripped from the beginning of every route name
            | before it is emitted into the generated TypeScript file. This is useful
            | when the module already implies the prefix — for example, a module named
            | 'store' can set stripPrefix to 'store.' so that 'store.products.list'
            | becomes 'products.list' in the output. Leave null to disable stripping.
            |
            */
            'stripPrefix' => null,
        ],
    ],
];
