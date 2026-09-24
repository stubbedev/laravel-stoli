# Laravel Stoli

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

`stubbedev/laravel-stoli` is a Laravel package that exports your application's named routes to TypeScript, enabling you to use route names instead of hardcoded URLs in your frontend code. It builds on top of [`spatie/laravel-data`](https://github.com/spatie/laravel-data) and [`spatie/laravel-typescript-transformer`](https://github.com/spatie/laravel-typescript-transformer) — both are required dependencies — and generates fully typed TypeScript definitions: route names, URI and domain parameters narrowed by their `where` constraints, and request and response types taken from the Data classes `typescript:transform` has already turned into TypeScript.

## Requirements

- PHP 8.2+
- Laravel 12 or 13
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
- `constants.ts` — the constants of the classes marked with `#[TypeScriptConstants]`

## Usage

### Basic

```typescript
import { RouteService } from "./stoli";
import routes from "./api";

const api = new RouteService({ routes });

api.generateFullURL("store.products.list");
// => https://example.com/api/store/products
```

Pass `rootUrl` to point the URLs somewhere else at runtime, a dev proxy say. It replaces the
generated host of every route except those with a domain of their own:

```typescript
const local = new RouteService({ routes, rootUrl: "http://localhost:8000" });
```

### Typed parameters

The generated route file exports `ApiRouteParams` and `ApiRouteName`. URI parameters (`{id}`, `{slug?}`) and route domain parameters (`{account}.example.com`) are always included. When a controller method accepts a `Spatie\LaravelData\Data` object, its generated type is intersected with them, so the request body is typed too.

```typescript
import routes, { type ApiRouteParams, type ApiRouteName } from "./api";
import { createRoute } from "./stoli";

const route = createRoute({ routes });

// Route name autocompletion + typed params
route("admin.products.update", { id: 42, name: "Notebook" });
//                               ^^        ^^^^^^^^^^^^^^^^
//               required URI param     from the ProductData request type

route("admin.products.update"); // type error: `id` is required
```

Required parameters are required in the types as well: a route with any may not be called
without them, while `{param?}` parameters may be left out or passed as `null`.

#### URI constraint types

When routes declare `->where()` constraints, the parameter type is narrowed accordingly:

```php
Route::get('/users/{id}', ...)->whereNumber('id');           // id: number
Route::get('/posts/{slug}', ...)->whereAlpha('slug');         // slug: string
Route::get('/items/{type}', ...)->whereIn('type', ['a','b']); // type: 'a' | 'b'
Route::get('/v/{version}', ...)->whereIn('version', [1, 2]);  // version: '1' | 1 | '2' | 2
```

Without a constraint the type is `string | number`. Optional parameters (`{param?}`) become `param?: type`.

### Typed responses

Each generated module also exports `ApiRouteResponse`, a per-route map of response types. A route is included when its controller method returns a `Spatie\LaravelData\Data` subclass, or a collection of one, that `php artisan typescript:transform` has turned into TypeScript. Always run `typescript:transform` before `stoli:generate`:

```bash
php artisan typescript:transform
php artisan stoli:generate
```

| Controller return type | Response type |
|---|---|
| `UserData` | `UserData` |
| `ApiResponseData` with `@return ApiResponseData<UserData>` | `ApiResponseData<UserData>` |
| `DataCollection`, `Collection` or `array` with `@return DataCollection<int, UserData>`, `list<UserData>` or `UserData[]` | `UserData[]` |
| `PaginatedDataCollection` with `@return PaginatedDataCollection<int, UserData>` | `Paginated<UserData>` |
| `CursorPaginatedDataCollection` with `@return CursorPaginatedDataCollection<int, UserData>` | `CursorPaginated<UserData>` |

`Paginated` and `CursorPaginated` describe the `data`/`links`/`meta` envelope laravel-data sends and are exported from `stoli.d.ts`. Class names in `@return` tags are resolved the way PHP resolves them in the controller's file: through its `use` imports, grouped and aliased ones included, then relative to its namespace.

Types written by the transformer's `GlobalNamespaceWriter` are ambient globals and are referenced by their namespace path; types written as module exports are imported, with the path computed relative to the module's output directory:

```typescript
// declare namespace output
'api.users.show': App.Data.UserData;

// module output
import type { UserData } from '../types/generated';
'api.users.show': UserData;
```

Response types follow laravel-data's wrapping. A returned Data object is wrapped under its
`defaultWrap()` key, or otherwise the `data.wrap` key in `config/data.php`
(`{ data: UserData }`); a `DataCollection` under the config key; and a paginated collection
has its `data` key renamed to it (`Paginated<UserData, 'items'>`). Plain arrays and Laravel
collections are sent by Laravel itself and never wrapped.

Routes without a resolvable response type are absent from the interface. The axios router (see below) falls back to `Record<string, unknown>` for those routes.

### Typed constants

PHP classes marked with `#[TypeScriptConstants]` have their public constants exported to
`constants.ts`, discovered the same way the typescript-transformer discovers the classes and
enums it transforms — by scanning the configured directories for the attribute.

```php
namespace App\Support;

use StubbeDev\LaravelStoli\Attributes\TypeScriptConstants;

#[TypeScriptConstants]
final class Permission
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
}
```

The PHP namespace is mirrored as nested objects, and each class also gets a union type of its
values:

```typescript
export const App = {
	Support: {
		Permission: {
			VIEW: 'view',
			EDIT: 'edit',
		},
	},
} as const;

export type Permission = (typeof App.Support.Permission)[keyof typeof App.Support.Permission];
```

```typescript
import { App, type Permission } from "./constants";

App.Support.Permission.VIEW;          // 'view'
const granted: Permission = 'edit';   // 'view' | 'edit'
```

Only public constants declared on the class itself are exported — inherited and interface
constants belong to the class that declares them. Enums are skipped: they are already
transformed to TypeScript by `typescript:transform`.

`#[TypeScriptConstants('Boundaries')]` publishes the class under a different key. When two
classes share a basename, the second type alias takes in its enclosing namespace segment
(`AdminStatus`) so both remain exported.

Classes carrying spatie's `#[TypeScript]` attribute are picked up as well, so a class that is
already transformed for its types contributes its constants too. Narrow `constants.attributes`
in `config/stoli.php` to Stoli's own attribute to opt out of that.

#### Supported constant values

| PHP value | TypeScript |
|---|---|
| `string`, `int`, `float`, `bool`, `null` | the literal value |
| List array | array literal |
| Associative array | object literal |
| Backed enum case | its backing value |
| Pure enum case | its case name as a string |
| `JsonSerializable` | its serialized value |

Constants holding anything else — a plain object, a resource — are left out, and a class left
without a single exportable constant is dropped from the file. When no constants are left
at all, a `constants.ts` an earlier run generated is removed; a directory that cannot be
scanned is reported as an error rather than leaving the old file in place.

### Axios router (optional)

Enable in `config/stoli.php`:

```php
'axios' => true,
```

The generated router (`router.ts`, or `<module>.router.ts` for each module when there are several) wraps axios with full type inference for both params and responses:

```typescript
import { Stoli } from "./router";

// params typed from ApiRouteParams, response typed from ApiRouteResponse
const response = await Stoli.get("api.users.show", { id: 1 });
response.data; // typed as UserData

// routes without a detected response fall back to Record<string, unknown>
const list = await Stoli.get("api.products.index");
list.data; // typed as Record<string, unknown>
```

#### File uploads

Pass files as normal params — a body containing a `File`/`Blob` anywhere, however deeply nested, is sent as `multipart/form-data`. Params stay fully typed, so file fields are checked against the Data type (map `UploadedFile` to `File` in your typescript-transformer config):

```typescript
await Stoli.post("api.folders.files.store", { folder: 42, file, title: "Report" });
```

The body is flattened the way PHP reads it back (`meta[tags][]`, `rows[0][id]`) with booleans sent as `1`/`0`, so Laravel validates it as it would a JSON body. PHP only parses multipart bodies on `POST`, so a `put` or `patch` carrying files is sent as a `POST` with `_method` set, which Laravel routes back to the `PUT` or `PATCH` route.

`post`, `put` and `patch` also accept a raw `FormData` for hand-built forms.
Route parameters are read from the FormData and stripped from the body. No type checking on the fields in this form:

```typescript
const form = new FormData();
form.append("folder", "42"); // fills {folder} in the URI
form.append("files[]", file);

await Stoli.post("api.folders.files.store", form); // POST /api/folders/42/files
```

Requires axios: `npm install axios`

### Route service methods

| Method | Description |
|--------|-------------|
| `generateFullURL(name, params?)` | Full URL; leftover params are appended as query string. Leaves `params` untouched |
| `createURLWithoutQuery(name, params?)` | URL with only URI params substituted; no query string. **Deletes** the substituted keys from `params` so the rest can be used as a body — pass a copy if you still need it |
| `has(name)` | Returns `true` if the route exists |

```typescript
api.generateFullURL("admin.products.show", { id: "abc-123", page: 2 });
// => https://example.com/api/admin/products/abc-123?page=2

api.createURLWithoutQuery("admin.products.show", { id: "abc-123", page: 2 });
// => https://example.com/api/admin/products/abc-123
```

Both throw on a missing required parameter rather than emitting a literal `{id}` in the URL.
Optional parameters (`{page?}`) may be omitted and take their path segment with them.

Query strings are written the way PHP's `http_build_query` writes them, so Laravel reads back
the same structure: arrays become `tags[]=x&tags[]=y`, nested objects `filter[name]=x`,
booleans `1`/`0` (what the `boolean` rule accepts), and dates ISO strings. The axios router
serializes its query parameters the same way. `serializeQuery()` is exported from `stoli.js`
for building a query string by hand.

## Configuration

Publish and edit `config/stoli.php` to customise.

```php
return [
    'split'  => true,        // true = one file per module, false = single file
    'axios'  => false,       // generate axios router wrapper
    'single' => [            // used when split = false
        'name' => 'api',     // output filename (without extension)
    ],
    'constants' => [
        'enabled'    => true,          // false = skip constant generation
        'name'       => 'constants',   // output filename (without extension)
        'path'       => null,          // defaults to typescript-transformer output dir
        'paths'      => null,          // dirs scanned; defaults to the transformer's own
        'attributes' => [              // the attributes that opt a class in
            StubbeDev\LaravelStoli\Attributes\TypeScriptConstants::class,
            Spatie\TypeScriptTransformer\Attributes\TypeScript::class,
        ],
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
            'names'       => null,                        // route name filter, e.g. 'app.*'
        ],
    ],
];
```

All generated files are written to the `outputDirectory` configured in `config/typescript-transformer.php`. Per-module `path` can override this for individual modules.

### Module options

| Option | Default | Description |
|--------|---------|-------------|
| `match` | `*` | URL prefix to filter routes. `*` matches all, `/api/store` matches `api/store` and the routes under it, but not `api/storefront` |
| `names` | `null` | Route name pattern, or list of patterns, a route must also match (`Str::is` syntax, e.g. `app.*`). `null` keeps every name |
| `standalone` | `false` | Keep the module in its own file even when `split` is `false`, and generate no axios router for it |
| `name` | — | Output filename (without extension) |
| `rootUrl` | `APP_URL` | Base URL for absolute URLs. A route with its own domain (`Route::domain()`) keeps its domain, with the scheme taken from this URL |
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

### Selecting routes by name

When the routes a consumer needs share a name prefix but not a URL prefix (the pages of a
single-page app, say), select them with `names` instead:

```php
'modules' => [
    ['match' => '/api', 'name' => 'api'],
    ['match' => '*', 'name' => 'app', 'names' => 'app.*', 'absolute' => false, 'standalone' => true],
],
```

`match` and `names` combine: a route lands in the module only when both accept it.
`standalone` keeps page routes like these out of the merged single file and out of the
axios router, which only makes sense for API routes.

## Development

```bash
just test      # phpunit, the stub checks and the generated TypeScript under tsc --strict
just analyse   # larastan at max level over config, src and tests
```

`tests/Integration/GeneratedTypeScriptTest.php` publishes the files for a set of fixture
routes into `tests/typescript/build` and compiles them against `tests/typescript/usage.ts`,
which asserts the types every route should narrow to. Run it outside docker after
`npm ci --prefix tests/typescript`.

## License

MIT. See [`LICENSE`](./LICENSE).
