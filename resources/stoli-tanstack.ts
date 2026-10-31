import type { DataTag, QueryFunction } from '@tanstack/query-core';
import type { Arguments, NamesFor, RouteDefinitions } from './stoli';

type Segments<Name extends string> = Name extends `${infer Head}.${infer Tail}` ? [Head, ...Segments<Tail>] : [Name];

type Prefixes<Name extends string> = Name extends `${infer Head}.${infer Tail}` ? Head | `${Head}.${Prefixes<Tail>}` : Name;

export type QueryName<R extends RouteDefinitions> = {
	[N in NamesFor<R, 'get'>]: [R[N]['response']] extends [void | undefined] ? never : N;
}[NamesFor<R, 'get'>];

export type QueryPrefix<R extends RouteDefinitions> = Prefixes<QueryName<R>>;

export type StoliQueryKey<R extends RouteDefinitions, N extends QueryName<R>, Namespace extends readonly string[]> = DataTag<
	[...Namespace, ...Segments<N>, R[N]['params']],
	R[N]['response']
>;

export type StoliKeyPrefix<P extends string, Namespace extends readonly string[]> = [...Namespace, ...Segments<P>];

export interface StoliQueryOptions<R extends RouteDefinitions, N extends QueryName<R>, Namespace extends readonly string[]> {
	queryKey: StoliQueryKey<R, N, Namespace>;
	queryFn: QueryFunction<R[N]['response'], StoliQueryKey<R, N, Namespace>>;
}

export type QueryGet<R extends RouteDefinitions> = <N extends QueryName<R>>(
	name: N,
	params: R[N]['params'],
	options: { signal: AbortSignal },
) => Promise<{ data: R[N]['response'] }>;

export interface StoliQueries<R extends RouteDefinitions, Namespace extends readonly string[]> {
	stoliQuery<N extends QueryName<R>>(name: N, ...parameters: Arguments<R[N]['params']>): StoliQueryOptions<R, N, Namespace>;
	stoliKey<N extends QueryName<R>>(name: N, params: R[N]['params']): StoliQueryKey<R, N, Namespace>;
	stoliKey<P extends QueryPrefix<R>>(prefix: P): StoliKeyPrefix<P, Namespace>;
}

export function createQueries<R extends RouteDefinitions, const Namespace extends readonly string[]>(
	get: QueryGet<R>,
	namespace: Namespace,
): StoliQueries<R, Namespace> {
	const key = (name: string, ...params: [object?]): unknown[] => [...namespace, ...name.split('.'), ...params];
	const query = (name: QueryName<R>, params: object = {}) => ({
		queryKey: key(name, params),
		queryFn: async ({ signal }: { signal: AbortSignal }) => (await get(name, params, { signal })).data,
	});

	return { stoliQuery: query, stoliKey: key } as unknown as StoliQueries<R, Namespace>;
}
