/**
 * Behaviour check for the shipped resources/stoli.stub — run with `node tests/routeservice.test.mjs`.
 *
 * The stub is plain JavaScript published verbatim into consumer projects, so it is loaded here
 * as a data: module rather than copied, keeping this file honest about what actually ships.
 */
import assert from 'node:assert';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../resources/stoli.stub', import.meta.url), 'utf8');
const { RouteService, createRoute, serializeQuery, flattenParameters } = await import(`data:text/javascript,${encodeURIComponent(source)}`);

const routes = {
	'users.show': { uri: 'users/{user}', host: null },
	'files.show': { uri: 'files/{path}', host: null },
	'posts.index': { uri: 'posts/{page?}', host: null },
	'tenant.home': { uri: '{tenant}/home', host: null },
	search: { uri: 'search', host: null },
	'tenant.dashboard': { uri: 'dashboard', host: 'https://{account}.example.test', domain: true },
};
const route = createRoute({ routes });

// Query values are encoded; arrays go out in the shape Laravel reads back as an array
assert.strictEqual(route('search', { q: 'a b&c=d' }), '/search?q=a+b%26c%3Dd');
assert.strictEqual(route('search', { tags: ['x', 'y z'] }), '/search?tags%5B%5D=x&tags%5B%5D=y+z');
assert.strictEqual(route('search', { q: 'x', empty: null, gone: undefined }), '/search?q=x');

// Booleans go out as 1/0, which Laravel's `boolean` rule accepts; 'false' would fail it
assert.strictEqual(route('search', { active: false, archived: true }), '/search?active=0&archived=1');

// Nested structures use PHP's bracket notation; structured list items get an index
assert.strictEqual(
	decodeURIComponent(serializeQuery({ filter: { name: 'x', tags: ['a'] }, rows: [{ id: 1, on: true }] })),
	'filter[name]=x&filter[tags][]=a&rows[0][id]=1&rows[0][on]=1',
);

// Dates are sent as ISO strings, not in the local format
assert.strictEqual(route('search', { at: new Date(0) }), '/search?at=1970-01-01T00%3A00%3A00.000Z');

// Files survive flattening untouched, for multipart bodies
const file = new Blob(['x']);
assert.deepStrictEqual(flattenParameters({ file, meta: { n: 1 } }), [['file', file], ['meta[n]', '1']]);

// Path values are encoded
assert.strictEqual(route('files.show', { path: 'my report.pdf' }), '/files/my%20report.pdf');

// generateFullURL leaves the caller's object alone...
const params = { user: 1, q: 'hi' };
assert.strictEqual(route('users.show', params), '/users/1?q=hi');
assert.deepStrictEqual(params, { user: 1, q: 'hi' }, 'generateFullURL must not mutate');

// ...while createURLWithoutQuery consumes the path params, which the axios router relies on
const consumed = { user: 1, name: 'x' };
const service = new RouteService({ routes });
assert.strictEqual(service.createURLWithoutQuery('users.show', consumed), '/users/1');
assert.deepStrictEqual(consumed, { name: 'x' }, 'path params must leave the body');

// Parameters are optional when the route has no required ones
assert.strictEqual(service.createURLWithoutQuery('posts.index'), '/posts');
assert.throws(() => service.createURLWithoutQuery('users.show'), /Missing required parameter "user"/);

// Parameters in the route domain are filled in and consumed like path parameters
assert.strictEqual(route('tenant.dashboard', { account: 'acme', tab: 'x' }), 'https://acme.example.test/dashboard?tab=x');
assert.throws(() => route('tenant.dashboard'), /Missing required parameter "account"/);

// A rootUrl replaces the default host, but not a route's own domain
const proxied = createRoute({ routes, rootUrl: 'http://localhost:8000' });
assert.strictEqual(proxied('search'), 'http://localhost:8000/search');
assert.strictEqual(proxied('tenant.dashboard', { account: 'acme' }), 'https://acme.example.test/dashboard');

// rootUrl with or without a trailing slash
assert.strictEqual(new RouteService({ routes, rootUrl: 'https://api.test/' }).generateFullURL('search'), 'https://api.test/search');
assert.strictEqual(new RouteService({ routes, rootUrl: 'https://api.test' }).generateFullURL('search'), 'https://api.test/search');

// A missing required parameter throws instead of shipping a literal {user} in the URL
assert.throws(() => route('users.show', {}), /Missing required parameter "user" for route: users\.show/);
assert.throws(() => route('users.show', { user: null }), /Missing required parameter/);

// Optional parameters: given, and absent (the segment goes with it)
assert.strictEqual(route('posts.index', { page: 2 }), '/posts/2');
assert.strictEqual(route('posts.index'), '/posts');

// A parameter at the start of the uri keeps its position
assert.strictEqual(route('tenant.home', { tenant: 'acme' }), '/acme/home');

// Unknown route still throws
assert.throws(() => route('nope'), /Not found route: nope/);

console.log('resources/stoli.stub: ok');
