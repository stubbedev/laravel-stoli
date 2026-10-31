import type { QueryClient } from '@tanstack/query-core';
import { stoliKey, stoliQuery } from '../resources/js/generated/api.query';
import type { ApiRouteResponse } from '../resources/js/generated/api';
import { expectType } from './types';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends (<T>() => T extends B ? 1 : 2) ? true : false;

export async function queries(client: QueryClient): Promise<void> {
	const user = await client.fetchQuery(stoliQuery('users.show', { user: 1 }));
	expectType<Equal<typeof user, ApiRouteResponse['users.show']>>();

	const cached = client.getQueryData(stoliKey('users.show', { user: 1 }));
	expectType<Equal<typeof cached, ApiRouteResponse['users.show'] | undefined>>();

	await client.invalidateQueries({ queryKey: stoliKey('users') });

	// @ts-expect-error a POST route is no query
	stoliQuery('users.store', { display_name: 'x' });
	// @ts-expect-error a numeric constraint takes no text
	stoliQuery('users.show', { user: 'x' });
}
