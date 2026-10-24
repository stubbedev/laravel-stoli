import axios, { type AxiosInstance, type AxiosRequestConfig, type AxiosResponse } from 'axios';
import {
	hookRegistry,
	isValidationErrors,
	requester,
	serializeQuery,
	withHooks,
	type Arguments,
	type Method,
	type NamesFor,
	type RouteDefinitions,
	type RouteHooks,
	type RouteMap,
	type RouteName,
	type ValidationErrors,
} from './stoli';

/**
 * An axios response, with the status the route responds with when it succeeds.
 */
export type StoliResponse<T, Status extends number = number> = AxiosResponse<T> & { status: Status };

/**
 * The response of each route.
 */
type Responses<R extends RouteDefinitions> = { [N in RouteName<R>]: StoliResponse<R[N]['response'], R[N]['status']> };

/**
 * Hooks on the calls to the routes $N, see Router.hooks and RequestOptions.
 */
export type RouterHooks<R extends RouteDefinitions, N extends RouteName<R> = RouteName<R>> = RouteHooks<R, Responses<R>, N>;

/**
 * The options of one call: axios' request config, and hooks run after the router's.
 */
export type RequestOptions<R extends RouteDefinitions, N extends RouteName<R>> = AxiosRequestConfig & { hooks?: RouterHooks<R, N> };

/**
 * One HTTP method of the router: only the routes answering to it, with their
 * parameters (or, for $Extra, a raw FormData), their response and its status.
 */
export type RouterMethod<R extends RouteDefinitions, M extends Method, Extra = never> = <N extends NamesFor<R, M>>(
	name: N,
	...args: Arguments<R[N]['params'] | Extra, [options?: RequestOptions<R, N>]>
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
	/**
	 * Run $hooks on every call from now on, before the call's own; returns the function
	 * that removes them again.
	 */
	hooks(hooks: RouterHooks<R>): () => void;
}

export interface RouterConfig {
	/** Replaces the generated host of every route without a domain of its own. */
	rootUrl?: string | null;
	/** The axios instance requests go through. */
	client?: AxiosInstance;
}

/**
 * Wrap axios with the route service: requests are made by route name, with the route's
 * parameters and response typed by the generated routes.
 */
export function createRouter<R extends RouteDefinitions>(
	routes: RouteMap<R>,
	{ rootUrl = null, client = axios }: RouterConfig = {},
): Router<R> {
	const prepare = requester({ routes: routes as RouteMap, rootUrl });
	const registry = hookRegistry<RouterHooks<RouteDefinitions>>();

	const perform = (method: Method, name: string, params: object | FormData | undefined, config: AxiosRequestConfig): Promise<AxiosResponse> => {
		const request = prepare(method, name, params);

		return client.request({
			// Query parameters are serialized the way generateFullURL does.
			paramsSerializer: (values) => serializeQuery(values),
			...config,
			method: request.method,
			url: request.url,
			params: { ...request.query, ...(config.params as Record<string, unknown> | undefined) },
			data: request.body ?? undefined,
		});
	};

	const call =
		(method: Method) =>
		(name: string, params?: object | FormData, { hooks, ...config }: RequestOptions<RouteDefinitions, string> = {}) =>
			withHooks(
				async () => perform(method, name, params, config),
				hooks === undefined ? registry.current() : [...registry.current(), hooks],
				{ name, method, params },
			);

	return {
		get: call('get'),
		post: call('post'),
		put: call('put'),
		patch: call('patch'),
		delete: call('delete'),
		validationErrors: (_name: string, error: unknown) =>
			axios.isAxiosError(error) && error.response?.status === 422 && isValidationErrors(error.response.data)
				? error.response.data
				: null,
		hooks: registry.add,
	} as Router<R>;
}
