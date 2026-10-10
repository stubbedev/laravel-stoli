/**
 * The generated router, fetch or axios, against `php artisan serve`.
 */
import assert from 'node:assert';
import { Stoli } from '../resources/js/generated/router';
import type { OrderDataInput } from '../resources/js/generated/api';

const order: OrderDataInput = {
	shipping: { street_name: 'Main 1', city: 'Aarhus' },
	billing: null,
	lines: [{ sku: 'a', quantity: 2 }],
	state: 'placed',
	status: 'active',
	reference: '9b2c5b7e-8f4e-4a57-9e0e-2c8e3f6a1b2c',
	note: 'leave at the door',
	coupon: 'WELCOME',
	password: 'secret',
	password_confirmation: 'secret',
	terms: true,
};

async function failure(request: Promise<unknown>): Promise<unknown> {
	try {
		await request;
	} catch (error) {
		return error;
	}

	return assert.fail('the request was expected to fail');
}

async function main(): Promise<void> {
	const shown = await Stoli.get('users.show', { user: 5 });
	assert.strictEqual(shown.status, 200);
	assert.deepStrictEqual(shown.data, { id: 5, name: 'user 5' });

	const created = await Stoli.post('users.store', { display_name: 'Ann', admin: true });
	assert.strictEqual(created.status, 201);
	assert.deepStrictEqual(created.data, { id: 1, name: 'Ann (admin)' });

	const updated = await Stoli.put('users.update', { user: 3, display_name: 'Bo' });
	assert.deepStrictEqual(updated.data, { id: 3, name: 'Bo' });

	const page = await Stoli.get('users.index', { page: 1 });
	assert.strictEqual(page.data.meta.total, 2);
	assert.deepStrictEqual(page.data.data.map((user) => user.id), [1, 2]);

	const wrapped = await Stoli.get('users.wrapped');
	assert.strictEqual(wrapped.data.data.id, 7);

	const placed = await Stoli.post('orders.store', order);
	assert.strictEqual(placed.status, 201);
	assert.strictEqual(placed.data.shipping.street, 'Main 1');
	assert.strictEqual(placed.data.lines[0]?.quantity, 2);

	const invalid = Stoli.validationErrors(
		'orders.store',
		await failure(Stoli.post('orders.store', { ...order, shipping: { city: 'x' }, lines: [{ quantity: 1 }], password_confirmation: 'other' } as never)),
	);
	assert.ok(invalid !== null, 'a failed validation');
	assert.ok(invalid.errors['shipping.street_name'], 'reported under the input name');
	assert.ok(invalid.errors['lines.0.sku'], 'reported under the item path');
	assert.ok(invalid.errors.password, 'the confirmation is checked');

	const category = await Stoli.post('categories.store', { name: 'a', children: [{ name: 'b', children: [{ name: 'c' }] }] });
	assert.strictEqual(category.data.children[0]?.children[0]?.name, 'c');

	const article = await Stoli.post('articles.store', {
		locale: 'da',
		title: 'T',
		status: 'archived',
		kind: 'page',
		authors: [{ name: 'A' }, { name: 'B', email: 'b@example.com' }],
		password: 'p',
		password_confirmation: 'p',
	});
	assert.deepStrictEqual(article.data, { title: 'T', authors: 2, locale: 'da' });

	const rejected = Stoli.validationErrors('articles.store', await failure(Stoli.post('articles.store', { locale: 'xx' } as never)));
	assert.ok(rejected?.errors.locale && rejected.errors.title && rejected.errors.authors);

	// Files go out as multipart, flattened the way PHP reads them back
	const file = new File(['abc'], 'a.txt');
	const uploaded = await Stoli.post('files.store', { folder: 4, file, meta: { tags: ['x', 'y'], flag: true } });
	assert.deepStrictEqual(uploaded.data, { folder: 4, name: 'a.txt', size: 3, method: 'POST', tags: ['x', 'y'], flag: true });

	// A PUT carrying files is spoofed through a POST, and arrives as the PUT
	const replaced = await Stoli.put('files.update', { folder: 4, file, meta: { tags: [], flag: false } });
	assert.strictEqual(replaced.data.method, 'PUT');
	assert.strictEqual(replaced.data.flag, false);

	// A raw FormData fills the path and keeps the rest
	const form = new FormData();
	form.append('folder', '9');
	form.append('file', file);
	assert.strictEqual((await Stoli.post('files.store', form)).data.folder, 9);

	const searched = await Stoli.get('search', { page: 2, tags: ['x'], filter: { a: '1' } });
	assert.deepStrictEqual(searched.data, { query: { tags: ['x'], filter: { a: '1' } } });

	const removed = await Stoli.delete('cart.remove', { product: 1 });
	assert.strictEqual(removed.status, 200);
	assert.ok(removed.data === undefined || removed.data === '', 'an empty body');

	console.log('http: router ok');
}

main().catch((error: unknown) => {
	console.error(error);
	process.exit(1);
});
