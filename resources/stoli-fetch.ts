import {
	isValidationErrors,
	requester,
	serializeQuery,
	type Arguments,
	type Method,
	type NamesFor,
	type RouteDefinitions,
	type RouteMap,
	type RouteName,
	type ValidationErrors,
} from './stoli';

/**
 * A response, shaped like axios' so the two routers can be swapped.
 */
export interface StoliResponse<T, Status extends number = number> {
	data: T;
	status: Status;
	headers: Headers;
	response: Response;
}

/**
 * Thrown for a response outside the 2xx range, like axios does.
 */
export class StoliHttpError extends Error {
	readonly response: StoliResponse<unknown>;

	constructor(response: StoliResponse<unknown>) {
		super(`Request failed with status code ${response.status}`);
		this.name = 'StoliHttpError';
		this.response = response;
	}
}

/**
 * One HTTP method of the router: only the routes answering to it, with their
 * parameters (or, for $Extra, a raw FormData), their response and its status.
 */
export type RouterMethod<R extends RouteDefinitions, M extends Method, Extra = never> = <N extends NamesFor<R, M>>(
	name: N,
	...args: Arguments<R[N]['params'] | Extra, [init?: RequestInit]>
) => Promise<StoliResponse<R[N]['response'], R[N]['status']>>;

export interface Router<R extends RouteDefinitions> {
	get: RouterMethod<R, 'get'>;
	post: RouterMethod<R, 'post', FormData>;
	put: RouterMethod<R, 'put', FormData>;
	patch: RouterMethod<R, 'patch', FormData>;
	delete: RouterMethod<R, 'delete'>;
	/**
	 * The validation errors a request to $name failed with, keyed by the fields of the
	 * route's parameters; null when $error is not a failed validation.
	 */
	validationErrors<N extends RouteName<R>>(name: N, error: unknown): ValidationErrors<R[N]['params']> | null;
}

export interface RouterConfig {
	/** Replaces the generated host of every route without a domain of its own. */
	rootUrl?: string | null;
	/** The fetch requests go through. */
	fetch?: typeof fetch;
	/** Options every request starts from; a call's own options take precedence. */
	init?: RequestInit;
}

/**
 * Wrap fetch with the route service: requests are made by route name, with the route's
 * parameters and response typed by the generated routes.
 *
 * Requests ask for JSON the way Laravel recognizes (so validation errors come back as
 * JSON), and carry the XSRF-TOKEN cookie as the X-XSRF-TOKEN header on same-origin
 * requests, as axios does.
 */
export function createRouter<R extends RouteDefinitions>(
	routes: RouteMap<R>,
	{ rootUrl = null, fetch: send = globalThis.fetch.bind(globalThis), init: defaults = {} }: RouterConfig = {},
): Router<R> {
	const prepare = requester({ routes: routes as RouteMap, rootUrl });

	const call =
		(method: Method) =>
		async (name: string, params?: object | FormData, init: RequestInit = {}): Promise<StoliResponse<unknown>> => {
			const request = prepare(method, name, params);
			const query = serializeQuery(request.query);
			const url = query === '' ? request.url : `${request.url}?${query}`;
			const headers = new Headers(defaults.headers);

			new Headers(init.headers).forEach((value, key) => headers.set(key, value));
			headers.set('Accept', headers.get('Accept') ?? 'application/json');
			headers.set('X-Requested-With', headers.get('X-Requested-With') ?? 'XMLHttpRequest');

			const xsrf = xsrfToken(url);

			if (xsrf !== null && !headers.has('X-XSRF-TOKEN')) {
				headers.set('X-XSRF-TOKEN', xsrf);
			}

			let body: BodyInit | null = null;

			if (request.body instanceof FormData) {
				body = request.body;
			} else if (request.body !== null) {
				body = JSON.stringify(request.body);
				headers.set('Content-Type', 'application/json');
			}

			const response = await send(url, { ...defaults, ...init, method: request.method.toUpperCase(), headers, body });
			const result: StoliResponse<unknown> = {
				data: await read(response),
				status: response.status,
				headers: response.headers,
				response,
			};

			if (!response.ok) {
				throw new StoliHttpError(result);
			}

			return result;
		};

	return {
		get: call('get'),
		post: call('post'),
		put: call('put'),
		patch: call('patch'),
		delete: call('delete'),
		validationErrors: (_name: string, error: unknown) =>
			error instanceof StoliHttpError && error.response.status === 422 && isValidationErrors(error.response.data)
				? error.response.data
				: null,
	} as Router<R>;
}

/**
 * The body as JSON when it is JSON, as text otherwise; an empty body is undefined.
 */
async function read(response: Response): Promise<unknown> {
	const text = await response.text();

	if (text === '') {
		return undefined;
	}

	return (response.headers.get('Content-Type') ?? '').includes('json') ? JSON.parse(text) : text;
}

/**
 * The XSRF-TOKEN cookie Laravel sets, for a request to the page's own origin.
 */
function xsrfToken(url: string): string | null {
	if (typeof document === 'undefined' || typeof location === 'undefined') {
		return null;
	}

	if (new URL(url, location.href).origin !== location.origin) {
		return null;
	}

	const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

	return cookie === undefined ? null : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}
