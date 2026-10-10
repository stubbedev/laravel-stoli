/**
 * Where a route is reached: its path, and the host it is built on.
 */
export interface Route {
	readonly uri: string;
	readonly host: string | null;
	/** Set when the host is the route's own domain, which a rootUrl does not replace. */
	readonly domain?: true;
}

/**
 * A `{parameter}` or `{parameter?}` in a route template, with the slash before it, so an
 * absent optional parameter takes its own path segment with it.
 */
const PLACEHOLDER = /\/?{(\w+)(\??)}/g;

const TRAILING_SLASHES = /\/+$/;

export type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';

/**
 * What a generated route file knows about one route: the parameters it takes, what it
 * responds with and with which status when it succeeds, and the HTTP methods it
 * answers to.
 */
export interface RouteDefinition {
	params: object;
	response: unknown;
	status: number;
	methods: Method;
}

export type RouteDefinitions = Record<string, RouteDefinition>;

declare const definitions: unique symbol;

/**
 * The routes of a generated file. Their definitions ride along in the type only, so
 * everything built from the routes knows each route's parameters and response.
 */
export type RouteMap<R extends RouteDefinitions = RouteDefinitions> = { readonly [N in RouteName<R>]: Route } & {
	readonly [definitions]?: R;
};

export type RouteName<R extends RouteDefinitions> = keyof R & string;

export type ParamsOf<R extends RouteDefinitions> = { [N in RouteName<R>]: R[N]['params'] };

export type ResponsesOf<R extends RouteDefinitions> = { [N in RouteName<R>]: R[N]['response'] };

export type StatusesOf<R extends RouteDefinitions> = { [N in RouteName<R>]: R[N]['status'] };

/**
 * The names of the routes that answer to an HTTP method.
 */
export type NamesFor<R extends RouteDefinitions, M extends Method> = {
	[N in RouteName<R>]: M extends R[N]['methods'] ? N : never;
}[RouteName<R>];

/**
 * The arguments after a route name: the parameters, which may only be left out when
 * none of them are required, followed by $Rest.
 */
export type Arguments<P, Rest extends unknown[] = []> = Record<string, never> extends P
	? [parameters?: P, ...Rest]
	: [parameters: P, ...Rest];

/**
 * A Data object as a request takes it, from the type generated for what it responds with.
 * $Fields maps each output key to the input key it is read from; an output key it leaves
 * out is not taken at all (a computed property). The input keys in $Optional may be left
 * out: those with a default, nullable ones and Optional ones. $Overrides gives the type
 * an output key takes in instead of the one it is sent as: the input type of a nested
 * Data object, or what its validation rules narrow it to. $Extra holds the input keys
 * it takes in that it does not send, such as the `password_confirmation` a `confirmed`
 * rule asks for.
 */
export type DataInput<
	T,
	Fields extends { [K in keyof T]?: string },
	Optional extends string = never,
	Overrides extends { [K in keyof T]?: unknown } = {},
	Extra extends object = {},
> = Simplify<
	{
		[K in keyof T & keyof Fields as InputKey<Fields[K]> extends Optional ? never : InputKey<Fields[K]>]: Override<T, K, Overrides>;
	} & {
		[K in keyof T & keyof Fields as InputKey<Fields[K]> extends Optional ? InputKey<Fields[K]> : never]?: Override<T, K, Overrides>;
	} & Extra
>;

type Override<T, K extends keyof T, Overrides> = K extends keyof Overrides ? Overrides[K] : T[K];

type InputKey<K> = K extends string ? K : never;

type Simplify<T> = { [K in keyof T]: T[K] } & {};

/**
 * The keys Laravel reports a validation error under for a field of $T: its name, and
 * for what it holds the dotted path to it, e.g. `address.street` or `items.0.name`.
 */
export type FieldPath<T, Depth extends 0[] = []> = Depth['length'] extends 8
	? string
	: T extends string | number | boolean | bigint | symbol | null | undefined | Blob | Date
		? never
		: T extends readonly (infer Item)[]
			? `${number}` | `${number}.${FieldPath<NonNullable<Item>, [...Depth, 0]>}`
			: T extends object
				? { [K in keyof T & string]-?: K | `${K}.${FieldPath<NonNullable<T[K]>, [...Depth, 0]>}` }[keyof T & string]
				: never;

/**
 * The body of a 422 response Laravel sends when the parameters $P fail validation.
 */
export interface ValidationErrors<P> {
	message: string;
	errors: { [K in FieldPath<P>]?: string[] };
}

/**
 * Whether a response body is the one Laravel sends for a failed validation.
 */
export function isValidationErrors(body: unknown): body is ValidationErrors<Record<string, unknown>> {
	return (
		typeof body === 'object' &&
		body !== null &&
		typeof (body as { message?: unknown }).message === 'string' &&
		typeof (body as { errors?: unknown }).errors === 'object' &&
		(body as { errors?: unknown }).errors !== null
	);
}

export interface StoliConfig<R extends RouteDefinitions = RouteDefinitions> {
	routes: RouteMap<R>;
	/** Replaces the generated host of every route without a domain of its own. */
	rootUrl?: string | null;
}

/**
 * A URL with the path parameters filled in, and the parameters it did not take: the
 * query string or request body.
 */
export interface Resolved {
	url: string;
	rest: Record<string, unknown>;
}

/**
 * The URL of a route without a query string, and the parameters left over for one or
 * for a request body. Throws when a required parameter is missing.
 *
 * @param rootUrl replaces the route's host unless the route has a domain of its own
 */
export function resolveRoute(name: string, route: Route, parameters: object = {}, rootUrl: string | null = null): Resolved {
	const rest: Record<string, unknown> = { ...parameters };

	// A route domain can hold parameters too.
	const fill = (template: string): string =>
		template.replace(PLACEHOLDER, (match, key: string, optional: string) => {
			const value = Object.hasOwn(rest, key) ? rest[key] : null;
			delete rest[key];

			if (value === null || value === undefined) {
				if (optional === '') {
					throw new Error(`Missing required parameter "${key}" for route: ${name}`);
				}

				return '';
			}

			return `${match.startsWith('/') ? '/' : ''}${encodeURIComponent(String(value))}`;
		});

	// A route with its own domain keeps it; rootUrl only replaces the default host.
	const host = route.domain ? (route.host ?? '') : (rootUrl ?? route.host ?? '');

	return { url: `${fill(host).replace(TRAILING_SLASHES, '')}/${fill(route.uri)}`, rest };
}

let defaultRootUrl: string | null = null;

/**
 * Point the URLs url() builds somewhere else, e.g. a dev proxy; null to build them on
 * each route's own host again.
 */
export function setRootUrl(rootUrl: string | null): void {
	defaultRootUrl = rootUrl;
}

/**
 * The full URL of a route, with the parameters the path does not take as its query
 * string. The per-route functions of a generated `<module>.urls.ts` are built on it, so
 * a bundle only holds the routes it uses.
 */
export function url(name: string, route: Route, parameters: object = {}): string {
	const resolved = resolveRoute(name, route, parameters, defaultRootUrl);
	const query = serializeQuery(resolved.rest);

	return query === '' ? resolved.url : `${resolved.url}?${query}`;
}

export class RouteService<R extends RouteDefinitions = RouteDefinitions> {
	readonly #routes: RouteMap<R>;
	readonly #rootUrl: string | null;

	constructor({ routes, rootUrl = null }: StoliConfig<R>) {
		this.#routes = routes;
		this.#rootUrl = rootUrl;
	}

	has(name: string): name is RouteName<R> {
		return Object.hasOwn(this.#routes, name);
	}

	/**
	 * The full URL, with the parameters the path does not take as its query string.
	 * Throws when the route is unknown or a required parameter is missing.
	 */
	generateFullURL<N extends RouteName<R>>(name: N, ...[parameters]: Arguments<R[N]['params']>): string {
		const resolved = this.#resolve(name, parameters);
		const query = serializeQuery(resolved.rest);

		return query === '' ? resolved.url : `${resolved.url}?${query}`;
	}

	/**
	 * The URL without a query string, and the parameters left over for one or for a
	 * request body. Throws when the route is unknown or a required parameter is missing.
	 */
	resolve<N extends RouteName<R>>(name: N, ...[parameters]: Arguments<R[N]['params']>): Resolved {
		return this.#resolve(name, parameters);
	}

	#resolve(name: string, parameters: object = {}): Resolved {
		const route = this.has(name) ? this.#routes[name] : undefined;

		if (route === undefined) {
			throw new Error(`Not found route: ${name}`);
		}

		return resolveRoute(name, route, parameters, this.#rootUrl);
	}
}

export default RouteService;

/**
 * A standalone `route()` function, typed by the generated routes it is given.
 *
 * @example
 * import { createRoute } from './stoli';
 * import routes from './api';
 *
 * const route = createRoute({ routes });
 * route('users.show', { user: 1 }); // → '/users/1'
 */
export function createRoute<R extends RouteDefinitions>(
	config: StoliConfig<R>,
): <N extends RouteName<R>>(name: N, ...parameters: Arguments<R[N]['params']>) => string {
	const service = new RouteService(config);

	return (name, ...parameters) => service.generateFullURL(name, ...parameters);
}

/**
 * The HTTP request a router call makes.
 */
export interface PreparedRequest {
	/** The method sent, which is POST for a PUT or PATCH carrying files. */
	method: Method;
	url: string;
	/** The parameters left over for the query string of a GET or DELETE. */
	query: Record<string, unknown>;
	/** The body of a POST, PUT or PATCH: multipart when it holds a file. */
	body: Record<string, unknown> | FormData | null;
}

/**
 * The untyped core the routers share: it turns a route name and its parameters into
 * the request to make. The routers put the types on top.
 */
export function requester(config: StoliConfig): (method: Method, name: string, params?: object | FormData) => PreparedRequest {
	const service = new RouteService(config);
	const resolve = service.resolve.bind(service) as (name: string, parameters?: object) => Resolved;

	return (method, name, params) => {
		let url: string;
		let rest: Record<string, unknown> | FormData;

		if (params instanceof FormData) {
			const fields: Record<string, unknown> = {};
			params.forEach((value, key) => {
				fields[key] = value;
			});
			const resolved = resolve(name, fields);

			// The fields the path took are left out of the body; FormData keeps repeated keys.
			const body = new FormData();
			params.forEach((value, key) => {
				if (Object.hasOwn(resolved.rest, key)) {
					body.append(key, value);
				}
			});

			({ url } = resolved);
			rest = body;
		} else {
			({ url, rest } = resolve(name, params));
		}

		if (method === 'get' || method === 'delete') {
			return { method, url, query: rest instanceof FormData ? {} : rest, body: null };
		}

		const body = toBody(rest);

		// PHP only parses multipart bodies on POST, so a PUT or PATCH carrying files is sent
		// as a POST that Laravel's method spoofing routes back to its route.
		if (method !== 'post' && body instanceof FormData) {
			if (!body.has('_method')) {
				body.append('_method', method.toUpperCase());
			}

			return { method: 'post', url, query: {}, body };
		}

		return { method, url, query: {}, body };
	};
}

/**
 * A body holding a File or Blob anywhere has to go out as multipart. It is flattened the
 * way PHP reads it back, so nested fields and booleans arrive as Laravel expects them.
 */
function toBody(body: Record<string, unknown> | FormData): Record<string, unknown> | FormData {
	if (body instanceof FormData) {
		return body;
	}

	const pairs = flattenParameters(body);

	if (!pairs.some(([, value]) => typeof Blob !== 'undefined' && value instanceof Blob)) {
		return body;
	}

	const form = new FormData();
	pairs.forEach(([key, value]) => form.append(key, value));

	return form;
}

/**
 * Flatten parameters into the key/value pairs PHP reads back into the same structure,
 * the way http_build_query writes them: nested keys in brackets, a list of scalars as
 * `tags[]`, booleans as 1 and 0 (what Laravel's `boolean` rule accepts) and dates as
 * ISO strings. null and undefined are left out; a File or Blob is kept as it is.
 */
export function flattenParameters(parameters: object): Array<[string, string | Blob]> {
	const pairs: Array<[string, string | Blob]> = [];

	const visit = (key: string, value: unknown): void => {
		if (value === null || value === undefined) {
			return;
		}

		if (typeof value === 'boolean') {
			pairs.push([key, value ? '1' : '0']);
		} else if (value instanceof Date) {
			pairs.push([key, value.toISOString()]);
		} else if (typeof Blob !== 'undefined' && value instanceof Blob) {
			pairs.push([key, value]);
		} else if (Array.isArray(value)) {
			// Items that are themselves structured need an index, or PHP starts a new
			// element for each of their keys.
			const indexed = value.some((item) => item !== null && typeof item === 'object' && !(item instanceof Date));
			value.forEach((item, index) => visit(indexed ? `${key}[${index}]` : `${key}[]`, item));
		} else if (typeof value === 'object') {
			Object.entries(value).forEach(([name, item]) => visit(`${key}[${name}]`, item));
		} else {
			pairs.push([key, String(value)]);
		}
	};

	Object.entries(parameters).forEach(([key, value]) => visit(key, value));

	return pairs;
}

/**
 * Serialize parameters as a query string Laravel reads back into the same structure.
 */
export function serializeQuery(parameters: object): string {
	const query = new URLSearchParams();

	flattenParameters(parameters).forEach(([key, value]) => query.append(key, typeof value === 'string' ? value : ''));

	return query.toString();
}

/**
 * A page link of a length-aware paginator.
 */
export interface PaginationLink {
	url: string | null;
	label: string;
	active: boolean;
}

/**
 * A spatie/laravel-data PaginatedDataCollection as it is sent. The items are under
 * `data`, or under the `data.wrap` key when laravel-data is configured to wrap.
 */
export type Paginated<T, Key extends string = 'data'> = { [K in Key]: T[] } & {
	links: PaginationLink[];
	meta: {
		current_page: number;
		first_page_url: string;
		from: number | null;
		last_page: number;
		last_page_url: string;
		next_page_url: string | null;
		path: string;
		per_page: number;
		prev_page_url: string | null;
		to: number | null;
		total: number;
	};
};

/**
 * A spatie/laravel-data CursorPaginatedDataCollection as it is sent. The items are
 * under `data`, or under the `data.wrap` key when laravel-data is configured to wrap.
 */
export type CursorPaginated<T, Key extends string = 'data'> = { [K in Key]: T[] } & {
	links: [];
	meta: {
		path: string;
		per_page: number;
		next_cursor: string | null;
		next_page_url: string | null;
		prev_cursor: string | null;
		prev_page_url: string | null;
	};
};
