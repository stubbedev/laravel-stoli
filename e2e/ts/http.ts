/**
 * Run against `php artisan serve`: the URLs the route service builds are the ones
 * Laravel's own route() builds, and Laravel reads their query strings back as sent.
 */
import assert from 'node:assert';
import { RouteService } from '../resources/js/generated/stoli';
import routes from '../resources/js/generated/api';
import pages from '../resources/js/generated/pages';

export const BASE = process.env['APP_URL'] ?? 'http://127.0.0.1:8000';

export async function json(url: string): Promise<unknown> {
	const response = await fetch(url, { headers: { Accept: 'application/json' } });
	assert.strictEqual(response.status, 200, `${url} responded ${response.status}: ${await response.clone().text()}`);

	return response.json();
}

/**
 * What Laravel's route() makes of a route and its parameters.
 */
export async function laravel(name: string, parameters: object): Promise<URL> {
	const check = new URL(`${BASE}/api/route-check`);
	check.searchParams.set('name', name);
	check.searchParams.set('parameters', JSON.stringify(parameters));

	return new URL(((await json(check.href)) as { url: string }).url);
}

/**
 * The URLs are the same where it matters: scheme, host and path. Laravel writes the
 * query string differently (tags[0]= for tags[]=), so that is checked by reading it back.
 */
export async function sameAsLaravel(url: string, name: string, parameters: object): Promise<void> {
	const ours = new URL(url, BASE);
	const theirs = await laravel(name, parameters);

	assert.strictEqual(`${ours.origin}${ours.pathname}`, `${theirs.origin}${theirs.pathname}`, `${name}: ${url} against ${theirs.href}`);
}

async function main(): Promise<void> {
	const service = new RouteService({ routes });

	await sameAsLaravel(service.generateFullURL('users.show', { user: 5 }), 'users.show', { user: 5 });
	await sameAsLaravel(service.generateFullURL('kinds.show', { kind: 'a' }), 'kinds.show', { kind: 'a' });
	await sameAsLaravel(service.generateFullURL('search', { page: 2 }), 'search', { page: 2 });
	await sameAsLaravel(service.generateFullURL('search'), 'search', {});
	await sameAsLaravel(new RouteService({ routes: pages }).generateFullURL('pages.show', { slug: 'about us' }), 'pages.show', { slug: 'about us' });

	// The query string is read back by Laravel as it was sent
	const echoed = await json(service.generateFullURL('search', { page: 2, tags: ['x', 'y z'], filter: { a: '1' }, on: true }));
	assert.deepStrictEqual(echoed, { query: { tags: ['x', 'y z'], filter: { a: '1' }, on: '1' } });

	console.log('http: route service ok');
}

if (process.argv[1]?.endsWith("http.cjs")) {
	main().catch((error: unknown) => {
		console.error(error);
		process.exit(1);
	});
}
