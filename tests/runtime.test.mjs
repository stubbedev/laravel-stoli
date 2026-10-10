/**
 * Behaviour check for the shipped runtime modules — run with `node tests/runtime.test.mjs`
 * after `npm ci --prefix tests/typescript`.
 *
 * The modules are published verbatim into consumer projects, so they are transpiled from
 * resources/ with the TypeScript compiler rather than copied, keeping this file honest
 * about what actually ships.
 */
import assert from 'node:assert';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dependencies = createRequire(new URL('./typescript/', import.meta.url));
const ts = dependencies('typescript');
const axiosUrl = pathToFileURL(dependencies.resolve('axios')).href;
const build = mkdtempSync(join(tmpdir(), 'stoli-runtime-'));
process.on('exit', () => rmSync(build, { recursive: true, force: true }));

const load = (module) => {
	const { outputText } = ts.transpileModule(readFileSync(new URL(`../resources/${module}.ts`, import.meta.url), 'utf8'), {
		compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
	});

	writeFileSync(join(build, `${module}.mjs`), outputText.replaceAll("from './stoli'", "from './stoli.mjs'").replaceAll("from 'axios'", `from '${axiosUrl}'`));

	return import(pathToFileURL(join(build, `${module}.mjs`)).href);
};

const { RouteService, createRoute, serializeQuery, flattenParameters, requester, url, setRootUrl, isValidationErrors } = await load('stoli');
const { createRouter, StoliHttpError } = await load('stoli-fetch');
const { createRouter: createAxiosRouter } = await load('stoli-axios');
const { default: axios } = await import(axiosUrl);

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

// ...and so does resolve, which hands back what the path did not take
const kept = { user: 1, name: 'x' };
const service = new RouteService({ routes });
assert.deepStrictEqual(service.resolve('users.show', kept), { url: '/users/1', rest: { name: 'x' } });
assert.deepStrictEqual(kept, { user: 1, name: 'x' }, 'resolve must not mutate');

// Parameters are optional when the route has no required ones
assert.deepStrictEqual(service.resolve('posts.index'), { url: '/posts', rest: {} });
assert.throws(() => service.resolve('users.show'), /Missing required parameter "user"/);

// A key inherited from Object.prototype is not a parameter
assert.throws(() => route('users.show', Object.create({ user: 1 })), /Missing required parameter "user"/);

// has() only knows the routes themselves
assert.strictEqual(service.has('users.show'), true);
assert.strictEqual(service.has('toString'), false);

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

// The request core: query for GET and DELETE, body for the rest
const prepare = requester({ routes });
assert.deepStrictEqual(prepare('get', 'users.show', { user: 1, q: 'x' }), { method: 'get', url: '/users/1', query: { q: 'x' }, body: null });
assert.deepStrictEqual(prepare('post', 'users.show', { user: 1, name: 'x' }), { method: 'post', url: '/users/1', query: {}, body: { name: 'x' } });

// A body holding a file is multipart, flattened the way PHP reads it back
const multipart = prepare('post', 'users.show', { user: 1, file, meta: { on: true } });
assert.ok(multipart.body instanceof FormData);
assert.deepStrictEqual([...multipart.body.keys()], ['file', 'meta[on]']);
assert.strictEqual(multipart.body.get('meta[on]'), '1');

// A PUT or PATCH carrying files is a POST spoofing its method
const spoofed = prepare('put', 'users.show', { user: 1, file });
assert.strictEqual(spoofed.method, 'post');
assert.strictEqual(spoofed.body.get('_method'), 'PUT');
assert.strictEqual(prepare('put', 'users.show', { user: 1, name: 'x' }).method, 'put');

// A raw FormData fills the path and keeps the rest, repeated keys included
const form = new FormData();
form.append('user', '7');
form.append('files[]', 'a');
form.append('files[]', 'b');
const fromForm = prepare('post', 'users.show', form);
assert.strictEqual(fromForm.url, '/users/7');
assert.deepStrictEqual([...fromForm.body.entries()], [['files[]', 'a'], ['files[]', 'b']]);

// The fetch router: JSON in and out, Laravel's headers, the query serialized like route()
const calls = [];
const respond = (status, body, type = 'application/json') => async (url, init) => {
	calls.push({ url, init });
	return new Response(body, { status, headers: body === null ? {} : { 'Content-Type': type } });
};

const fetched = await createRouter(routes, { fetch: respond(200, '{"id":1}') }).get('users.show', { user: 1, tags: ['a'] });
assert.deepStrictEqual(fetched.data, { id: 1 });
assert.strictEqual(fetched.status, 200);
assert.strictEqual(calls.at(-1).url, '/users/1?tags%5B%5D=a');
assert.strictEqual(calls.at(-1).init.method, 'GET');
assert.strictEqual(calls.at(-1).init.headers.get('Accept'), 'application/json');
assert.strictEqual(calls.at(-1).init.headers.get('X-Requested-With'), 'XMLHttpRequest');
assert.strictEqual(calls.at(-1).init.body, null);

await createRouter(routes, { fetch: respond(204, null), init: { headers: { 'X-Default': '1' } } }).post('users.show', { user: 1, name: 'x' }, { headers: { 'X-Call': '1' } });
assert.strictEqual(calls.at(-1).init.body, '{"name":"x"}');
assert.strictEqual(calls.at(-1).init.headers.get('Content-Type'), 'application/json');
assert.strictEqual(calls.at(-1).init.headers.get('X-Default'), '1');
assert.strictEqual(calls.at(-1).init.headers.get('X-Call'), '1');

const empty = await createRouter(routes, { fetch: respond(204, null) }).delete('users.show', { user: 1 });
assert.strictEqual(empty.data, undefined);

// Outside 2xx it throws, like axios, with the response at hand
await assert.rejects(
	createRouter(routes, { fetch: respond(422, '{"message":"invalid"}') }).post('users.show', { user: 1 }),
	(error) => error instanceof StoliHttpError && error.response.status === 422 && error.response.data.message === 'invalid',
);

// The axios router makes the same requests through the instance it is given
const sent = [];
const client = axios.create({
	adapter: async (config) => {
		sent.push(config);
		return { data: { id: 1 }, status: 200, statusText: 'OK', headers: {}, config };
	},
});
const viaAxios = createAxiosRouter(routes, { client });

assert.deepStrictEqual((await viaAxios.get('users.show', { user: 1, tags: ['a'] })).data, { id: 1 });
assert.strictEqual(sent.at(-1).method, 'get');
assert.strictEqual(axios.getUri(sent.at(-1)), '/users/1?tags%5B%5D=a');

await viaAxios.patch('users.show', { user: 1, file });
assert.strictEqual(sent.at(-1).method, 'post');
assert.strictEqual(sent.at(-1).url, '/users/1');
assert.strictEqual(sent.at(-1).data.get('_method'), 'PATCH');

await viaAxios.post('users.show', { user: 1, name: 'x' });
assert.strictEqual(sent.at(-1).data, '{"name":"x"}');

// The per-route URL functions build on url(), which setRootUrl() points elsewhere
const show = { uri: 'users/{user}', host: 'https://app.test' };
assert.strictEqual(url('users.show', show, { user: 1, q: 'x' }), 'https://app.test/users/1?q=x');
setRootUrl('http://localhost:8000');
assert.strictEqual(url('users.show', show, { user: 1 }), 'http://localhost:8000/users/1');
assert.strictEqual(url('tenant.dashboard', routes['tenant.dashboard'], { account: 'a' }), 'https://a.example.test/dashboard', 'a domain stays');
setRootUrl(null);
assert.strictEqual(url('users.show', show, { user: 1 }), 'https://app.test/users/1');
assert.throws(() => url('users.show', show), /Missing required parameter "user"/);

// A failed validation is recognized by its body, for both routers
const invalid = { message: 'The name field is required.', errors: { name: ['The name field is required.'] } };
assert.strictEqual(isValidationErrors(invalid), true);
assert.strictEqual(isValidationErrors({ message: 'x' }), false);
assert.strictEqual(isValidationErrors(null), false);

try {
	await createRouter(routes, { fetch: respond(422, JSON.stringify(invalid)) }).post('users.show', { user: 1 });
	assert.fail('a 422 throws');
} catch (error) {
	assert.deepStrictEqual(createRouter(routes).validationErrors('users.show', error), invalid);
}

try {
	await createRouter(routes, { fetch: respond(500, '{"message":"x"}') }).post('users.show', { user: 1 });
} catch (error) {
	assert.strictEqual(createRouter(routes).validationErrors('users.show', error), null, 'only a 422 is a failed validation');
}

const failing = axios.create({
	adapter: async (config) => {
		const error = new axios.AxiosError('invalid', 'ERR_BAD_REQUEST', config, null, { data: invalid, status: 422, statusText: '', headers: {}, config });
		throw error;
	},
});

try {
	await createAxiosRouter(routes, { client: failing }).post('users.show', { user: 1 });
	assert.fail('a 422 throws');
} catch (error) {
	assert.deepStrictEqual(createAxiosRouter(routes).validationErrors('users.show', error), invalid);
}

assert.strictEqual(createAxiosRouter(routes).validationErrors('users.show', new Error('x')), null);

console.log('resources/stoli.ts, resources/stoli-fetch.ts, resources/stoli-axios.ts: ok');
