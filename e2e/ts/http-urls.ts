/**
 * The per-route URL functions against Laravel's own route().
 */
import { usersShow, search, kindsShow } from '../resources/js/generated/api.urls';
import { pagesShow } from '../resources/js/generated/pages.urls';
import { setRootUrl } from '../resources/js/generated/stoli';
import assert from 'node:assert';
import { sameAsLaravel } from './http';

async function main(): Promise<void> {
	await sameAsLaravel(usersShow({ user: 5 }), 'users.show', { user: 5 });
	await sameAsLaravel(search({ page: 3 }), 'search', { page: 3 });
	await sameAsLaravel(kindsShow({ kind: 'b' }), 'kinds.show', { kind: 'b' });
	await sameAsLaravel(pagesShow({ slug: 'about' }), 'pages.show', { slug: 'about' });

	setRootUrl('http://proxy.test');
	assert.strictEqual(usersShow({ user: 5 }), 'http://proxy.test/api/users/5');
	setRootUrl(null);

	console.log('http: urls ok');
}

main().catch((error: unknown) => {
	console.error(error);
	process.exit(1);
});
