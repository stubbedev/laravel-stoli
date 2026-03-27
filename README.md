# Laravel Stoli

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](license)

`stubbedev/laravel-stoli` is a Laravel package that exports your application's named routes to TypeScript, enabling you to use route names instead of hardcoded URLs in your frontend code. It builds on top of [`spatie/laravel-data`](https://github.com/spatie/laravel-data) and [`spatie/laravel-typescript-transformer`](https://github.com/spatie/laravel-typescript-transformer) — both are required dependencies — and generates fully typed TypeScript definitions including parameter types inferred from FormRequest validation rules, URI constraint types, and response types derived from Data classes and the transformer's generated output.

## Requirements

- PHP 8.2+
- Laravel 11.15+
- `spatie/laravel-data` ^3|^4
- `spatie/laravel-typescript-transformer` ^3

## Installation

```bash
composer require stubbedev/laravel-stoli
```

Publish and configure `spatie/laravel-typescript-transformer` first — Stoli uses its output directory as the destination for all generated files:

```bash
php artisan vendor:publish --provider="Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerServiceProvider"
```

Then publish the Stoli configuration:

```bash
php artisan vendor:publish --tag='stoli'
```

Generate routes and types:

```bash
php artisan typescript:transform   # generates types from Data classes
php artisan stoli:generate         # generates route files referencing those types
```

`stoli:generate` writes the following into the typescript-transformer output directory:

- `stoli.js` — the `RouteService` runtime
- `stoli.d.ts` — TypeScript declarations
- `api.ts` (or one file per module) — typed route definitions

## Usage

### Basic

```typescript
import { RouteService } from "./stoli";
import routes from "./api";

const api = new RouteService({ routes });

api.generateFullURL("store.products.list");
// => https://example.com/api/store/products
```

### Typed parameters

The generated route file exports `ApiRouteParams` and `ApiRouteName`. URI parameters (`{id}`, `{slug?}`) are always included. When a controller method accepts a `FormRequest`, its validation rules are also included as typed fields.

```typescript
import routes, { type ApiRouteParams, type ApiRouteName } from "./api";
import { RouteService } from "./stoli";

const api = new RouteService({ routes });

// Route name autocompletion + typed params
api.generateFullURL("admin.products.update", { id: 42, name: "Notebook" });
//                                             ^^              ^^^^^^^^^^^
//                   required URI param (string | number)   from FormRequest
```

#### URI constraint types

When routes declare `->where()` constraints, the parameter type is narrowed accordingly:

```php
Route::get('/users/{id}', ...)->whereNumber('id');           // id: number
Route::get('/posts/{slug}', ...)->whereAlpha('slug');         // slug: string
Route::get('/items/{type}', ...)->whereIn('type', ['a','b']); // type: 'a' | 'b'
```

Without a constraint the type is `string | number`. Optional parameters (`{param?}`) become `param?: type`.

#### FormRequest parameter types

Validation rules are reflected directly into TypeScript:

| Laravel rule | TypeScript type |
|---|---|
| `string`, `email`, `url`, `uuid`, … | `string` |
| `integer`, `numeric`, `decimal:…` | `number` |
| `boolean`, `accepted`, `declined` | `boolean` |
| `array` | `Record<string, unknown>` |
| `list`, `distinct` | `unknown[]` |
| `file`, `image` | `File` |
| `in:a,b,c` / `Rule::enum(MyEnum::class)` | `'a' \| 'b' \| 'c'` |
| `nullable` modifier | adds `\| null` |
| Nested dot-notation (`address.city`) | inline object type |
| Wildcard arrays (`tags.*`) | `string[]` / `{ … }[]` |

### Typed responses

Each generated module also exports `ApiRouteResponse`, a per-route map of response shapes. A route is included in the interface when its controller method has a return type annotation that is a `Spatie\LaravelData\Data` subclass and that class has been transformed by `php artisan typescript:transform`.

The generated interface looks like:

```typescript
import type { UserData, UserResource } from '../types/generated';

export interface ApiRouteResponse {
    'api.users.show': UserData;
    'api.users.index': UserResource;
}
```

Routes without a resolvable Data return type are absent from the interface. The axios router (see below) falls back to `Record<string, unknown>` for those routes.

#### Spatie Laravel Data

Controllers returning `Spatie\LaravelData\Data` objects are detected automatically. The TypeScript shape is derived from the class's public typed properties:

```php
class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $bio,
        public AddressData $address,

        #[DataCollectionOf(TagData::class)]
        public DataCollection $tags,
    ) {}
}
```

Generates:

```typescript
'api.users.show': { id: number; name: string; bio: string | null; address: { street: string; city: string }; tags: { id: number; label: string }[] }
```

Supported property types:

| PHP type | TypeScript type |
|---|---|
| `int`, `float` | `number` |
| `string` | `string` |
| `bool` | `boolean` |
| `?type` / `type\|null` | `type \| null` |
| Nested `Data` subclass | inline object type |
| `DataCollection` + `#[DataCollectionOf(T::class)]` | `T[]` |
| `array` + `#[DataCollectionOf(T::class)]` | `T[]` |
| `DateTimeInterface` | `string` |
| `Collection` | `unknown[]` |

#### Spatie Laravel TypeScript Transformer

When `php artisan typescript:transform` has already been run, Stoli uses the generated type names directly instead of re-deriving inline shapes:

```typescript
// Without typescript:transform (inline shape):
'api.users.show': { id: number; name: string; email: string | null };

// With typescript:transform (type reference + import):
import type { UserData } from '../types/generated';
// ...
'api.users.show': UserData;
```

The import path is computed relative to the module's output directory. Always run `typescript:transform` before `stoli:generate`:

```bash
php artisan typescript:transform
php artisan stoli:generate
```

### Axios router (optional)

Enable in `config/stoli.php`:

```php
'axios' => true,
```

The generated router (`api.router.ts`) wraps axios with full type inference for both params and responses:

```typescript
import { Stoli } from "./api.router";

// params typed from ApiRouteParams, response typed from ApiRouteResponse
const response = await Stoli.get("api.users.show", { id: 1 });
response.data; // typed as { data: { id: number; name: string; email: string } }

// routes without a detected response fall back to Record<string, unknown>
const list = await Stoli.get("api.products.index");
list.data; // typed as Record<string, unknown>
```

Requires axios: `npm install axios`

### Route service methods

| Method | Description |
|--------|-------------|
| `generateFullURL(name, params?)` | Full URL; leftover params are appended as query string |
| `createURLWithoutQuery(name, params?)` | URL with only URI params substituted; no query string |
| `has(name)` | Returns `true` if the route exists |

```typescript
api.generateFullURL("admin.products.show", { id: "abc-123", page: 2 });
// => https://example.com/api/admin/products/abc-123?page=2

api.createURLWithoutQuery("admin.products.show", { id: "abc-123", page: 2 });
// => https://example.com/api/admin/products/abc-123
```

## Configuration

Publish and edit `config/stoli.php` to customise.

```php
return [
    'split'  => true,        // true = one file per module, false = single file
    'axios'  => false,       // generate axios router wrapper
    'single' => [            // used when split = false
        'name' => 'api',     // output filename (without extension)
    ],
    'modules' => [
        [
            'match'       => '*',                         // route prefix filter (* = all)
            'name'        => 'api',                       // output file name (api.ts)
            'rootUrl'     => env('APP_URL', 'http://localhost'),
            'absolute'    => true,
            'prefix'      => null,
            'path'        => null,                        // defaults to typescript-transformer output dir
            'stripPrefix' => null,
        ],
    ],
];
```

All generated files are written to the `output_path` configured in `config/typescript-transformer.php`. Per-module `path` can override this for individual modules.

### Module options

| Option | Default | Description |
|--------|---------|-------------|
| `match` | `*` | URL prefix to filter routes. `*` matches all, `/api/store` matches only routes under that path |
| `name` | — | Output filename (without extension) |
| `rootUrl` | `APP_URL` | Base URL for absolute URLs |
| `absolute` | `true` | Generate absolute (`https://…`) or relative (`/…`) URLs |
| `prefix` | `null` | Prefix prepended to every generated URL |
| `path` | transformer output dir | Output directory for this module's `.ts` file |
| `stripPrefix` | `null` | Route name prefix to strip (e.g. `store.` turns `store.products.list` into `products.list`) |

### Multiple modules

Split routes into separate typed files per API consumer:

```php
'modules' => [
    [
        'match'   => '/api/store',
        'name'    => 'store',
        'rootUrl' => 'https://store.example.com',
    ],
    [
        'match'   => '/api/admin',
        'name'    => 'admin',
        'rootUrl' => 'https://admin.example.com',
    ],
],
```

This generates `store.ts` and `admin.ts`, each with their own `StoreRouteParams` / `AdminRouteParams` / `StoreRouteResponse` / `AdminRouteResponse` interfaces.

## License

MIT. See [`license`](./license).
