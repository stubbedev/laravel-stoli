import assert from 'node:assert';
import { QueryClient } from '@tanstack/query-core';
import { stoliKey, stoliQuery } from '../resources/js/generated/api.query';

async function main(): Promise<void> {
	const client = new QueryClient();

	const user = await client.fetchQuery(stoliQuery('users.show', { user: 5 }));
	assert.deepStrictEqual(user, { id: 5, name: 'user 5' });
	assert.deepStrictEqual(client.getQueryData(stoliKey('users.show', { user: 5 })), user);

	const page = await client.fetchQuery(stoliQuery('users.index', { page: 1 }));
	assert.deepStrictEqual(page.data.map((listed) => listed.id), [1, 2]);

	const searched = await client.fetchQuery(stoliQuery('search', { page: 2, tags: ['x'] }));
	assert.deepStrictEqual(searched, { query: { tags: ['x'] } });

	await client.invalidateQueries({ queryKey: stoliKey('users') });
	assert.strictEqual(client.getQueryState(stoliKey('users.show', { user: 5 }))?.isInvalidated, true);
	assert.strictEqual(client.getQueryState(stoliKey('search', { page: 2, tags: ['x'] }))?.isInvalidated, false);

	console.log('http: queries ok');
}

main().catch((error: unknown) => {
	console.error(error);
	process.exit(1);
});
