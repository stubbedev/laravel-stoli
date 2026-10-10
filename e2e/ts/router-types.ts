/**
 * Compiled with types.ts when the scenario generates a router; it is the same for
 * either client.
 */
import { Stoli } from '../resources/js/generated/router';
import type { ApiRouteResponse } from '../resources/js/generated/api';
import { expectType } from './types';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends (<T>() => T extends B ? 1 : 2) ? true : false;

export async function router(): Promise<void> {
	const created = await Stoli.post('users.store', { display_name: 'x' });
	expectType<Equal<typeof created.data, ApiRouteResponse['users.store']>>();
	expectType<Equal<typeof created.status, 201>>();

	try {
		await Stoli.post('orders.store', { shipping: { street_name: 'a', city: 'b' } } as never);
	} catch (error) {
		const failed = Stoli.validationErrors('orders.store', error);
		expectType<Equal<NonNullable<typeof failed>['errors']['shipping.street_name'], string[] | undefined>>();
		// @ts-expect-error an error is reported under the input name
		failed?.errors['shipping.street'];
	}

	// @ts-expect-error a POST route is not a GET route
	await Stoli.get('users.store');
}
