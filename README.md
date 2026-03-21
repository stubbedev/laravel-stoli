# Laravel Stoli

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](license)

`stubbedev/laravel-stoli` is a Laravel package that exports your application's named routes to TypeScript, enabling you to use route names instead of hardcoded URLs in your frontend code. It generates fully typed TypeScript definitions — including parameter types inferred from FormRequest validation rules, URI constraint types, and response types inferred from JsonResource classes.

## Installation

Requires PHP 8.4 and Laravel 11.15+.

```bash
composer require stubbedev/laravel-stoli
```

Publish the configuration and JS library files:

```bash
php artisan vendor:publish --tag='stoli'
php artisan stoli:generate
```

This produces two files in your configured `library` path:

- `stoli.js` — the `RouteService` runtime
- `stoli.d.ts` — TypeScript declarations

It also generates a `.ts` route file per module (e.g. `api.ts`).

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
Route::get('/users/{id}', ...)->whereNumber('id');          // id: number
Route::get('/posts/{slug}', ...)->whereAlpha('slug');        // slug: string
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

Each generated module also exports `ApiRouteResponse`, a per-route map of response shapes. Response types are inferred automatically — no annotations needed.

**Resolution order (first match wins):**

1. **Actual execution** — for GET routes, the controller is called via the container with seeded model instances and a test user. The real JSON response is used to infer types exactly. Run with `--env=testing` to use your test database.
2. **Return type annotation** — if the controller method is annotated with a `JsonResource` or `Spatie\LaravelData\Data` subclass as its return type, the response shape is extracted statically.
3. **Method body analysis** — the controller body is tokenised to find the array passed to response methods. Resource calls (`::make()`, `::collection()`, `new Resource()`) and Data calls (`::from()`, `::collect()`, `new DataClass()`) within array values are resolved to their shapes. The wrapping key (e.g. `data`) is detected by calling the response wrapper method with an empty payload.

The generated interface looks like:

```typescript
export interface ApiRouteResponse {
    'api.users.show': { data: { id: number; name: string; email: string } };
    'api.users.index': { data: { id: number; name: string; email: string }[] };
    'api.downloadtemplates.index': { data: { templates: { id: number; name: string; ... }[]; sections: unknown }; message: string; status: number };
}
```

Routes where no response can be detected are absent from the interface. The axios router (see below) falls back to `Record<string, unknown>` for those routes.

#### Spatie Laravel TypeScript Transformer

If [`spatie/laravel-typescript-transformer`](https://github.com/spatie/laravel-typescript-transformer) is installed and has already generated its output file (via `php artisan typescript:transform`), Stoli uses the generated type names directly instead of re-deriving them:

```typescript
// Without laravel-typescript-transformer (inline shape):
'api.users.show': { id: number; name: string; email: string | null };

// With laravel-typescript-transformer (type reference + import):
import type { UserData } from '../types/generated';
// ...
'api.users.show': UserData;
```

The import path is computed relative to the module's output directory. The `output_file` in `config/typescript-transformer.php` is used to locate the generated definitions file.

Run in the right order:

```bash
php artisan typescript:transform   # generate TypeScript types
php artisan stoli:generate         # reference them in route types
```

#### Spatie Laravel Data

Controllers returning [`spatie/laravel-data`](https://spatie.be/docs/laravel-data) Data objects are detected automatically. The TypeScript shape is derived from the class's public typed properties:

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

#### Actual execution with a test database

For the most accurate types, run generation against your test environment:

```bash
php artisan stoli:generate --env=testing --force
```

Stoli will:
- Wrap each route execution in a `DB::beginTransaction()` / `DB::rollBack()` so nothing is persisted
- Create model instances for URI route parameters using their factory (`Model::factory()->create()`), falling back to schema-derived dummy values when no factory exists
- Authenticate as a factory-created user from the configured auth model

### Axios router (optional)

Publish the axios router stub:

```bash
php artisan vendor:publish --tag='stoli'
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
    'library' => 'resources/routes',   // where stoli.js / stoli.d.ts are published
    'split'   => true,                  // true = one file per module, false = single file
    'single'  => [                      // used when split = false
        'name' => 'api',
        'path' => 'resources/routes',
    ],
    'modules' => [
        [
            'match'    => '*',                        // route prefix filter (* = all)
            'name'     => 'api',                      // output file name (api.ts)
            'rootUrl'  => env('APP_URL', 'http://localhost'),
            'absolute' => true,
            'prefix'   => null,
            'path'     => 'resources/routes',
        ],
    ],
];
```

### Module options

| Option | Default | Description |
|--------|---------|-------------|
| `match` | `*` | URL prefix to filter routes. `*` matches all, `/api/store` matches only routes under that path |
| `name` | — | Output filename (without extension) |
| `rootUrl` | `APP_URL` | Base URL for absolute URLs |
| `absolute` | `true` | Generate absolute (`https://…`) or relative (`/…`) URLs |
| `prefix` | `null` | Prefix prepended to every generated URL |
| `path` | `library` value | Output directory for this module's `.ts` file |

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
