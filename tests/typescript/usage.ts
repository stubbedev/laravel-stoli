/**
 * Compiled with `tsc --strict` against what stoli:generate wrote into ./build, see
 * tests/Integration/GeneratedTypeScriptTest.php. Nothing runs: the checks are the types.
 *
 * Every `@ts-expect-error` is a call that must be rejected; tsc fails when one of them
 * type-checks after all, so a type that loosens is caught as surely as one that breaks.
 */
import { createRoute, RouteService, type CursorPaginated, type Paginated } from './build/stoli';
import routes, {
	type ApiDeleteRouteName,
	type ApiGetRouteName,
	type ApiPostRouteName,
	type ApiRouteName,
	type ApiRouteParams,
	type ApiRouteResponse,
} from './build/api';
import { Stoli } from './build/router';
import { StubbeDev as Constants } from './build/constants';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends (<T>() => T extends B ? 1 : 2) ? true : false;

function expectType<T extends true>(): void {}

type User = StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data.UserData;
type Wrapped<T> = StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data.ApiResponseData<T>;
type WrappedUser = StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data.WrappedUserData;

// Route names are literal, so they autocomplete and a typo is an error
expectType<Equal<'users.show' extends ApiRouteName ? true : false, true>>();
expectType<Equal<'nope' extends ApiRouteName ? true : false, false>>();

// Parameters narrow to their constraints
expectType<Equal<ApiRouteParams['users.show']['user'], number>>();
expectType<Equal<ApiRouteParams['kinds.show']['kind'], 'a' | 'b'>>();
expectType<Equal<ApiRouteParams['versions.show']['version'], '1' | 1 | '2' | 2>>();
expectType<Equal<ApiRouteParams['tenant.dashboard']['account'], string | number>>();

// Responses resolve per route, through collections and pagination
expectType<Equal<ApiRouteResponse['users.show'], User>>();
expectType<Equal<ApiRouteResponse['users.index'], User[]>>();
expectType<Equal<ApiRouteResponse['users.list'], User[]>>();
expectType<Equal<ApiRouteResponse['users.paginated'], Paginated<User>>>();
expectType<Equal<ApiRouteResponse['users.cursor'], CursorPaginated<User>>>();
expectType<Equal<ApiRouteResponse['users.wrapped'], Wrapped<User>>>();

// laravel-data wrapping: a class's defaultWrap() key, and a paginated envelope's items key
expectType<Equal<ApiRouteResponse['users.wrappedByClass'], { user: WrappedUser }>>();
expectType<Equal<Paginated<User, 'items'>['items'], User[]>>();
expectType<Equal<Paginated<User, 'items'>['meta']['total'], number>>();
expectType<Equal<'data' extends keyof Paginated<User, 'items'> ? true : false, false>>();

// Each HTTP method only takes its own routes
expectType<Equal<'users.show' extends ApiGetRouteName ? true : false, true>>();
expectType<Equal<'users.store' extends ApiGetRouteName ? true : false, false>>();
expectType<Equal<'users.store' extends ApiPostRouteName ? true : false, true>>();
expectType<Equal<ApiDeleteRouteName, 'store.cart.remove'>>();

// The standalone route() function and the service
const route = createRoute({ routes });
route('users.show', { user: 1 });
route('posts.index');
route('posts.index', { page: null });
route('tenant.dashboard', { account: 'acme', tab: 'x' });
new RouteService({ routes }).createURLWithoutQuery('users.index');
// @ts-expect-error unknown route
route('nope');
// @ts-expect-error a required parameter cannot be left out
route('users.show');
// @ts-expect-error nor be missing from the parameters
route('users.show', {});
// @ts-expect-error a domain parameter is required as well
route('tenant.dashboard');

async function router(): Promise<void> {
	const user = await Stoli.get('users.show', { user: 1 });
	expectType<Equal<typeof user.data, User>>();

	const page = await Stoli.get('users.paginated');
	expectType<Equal<typeof page.data.meta.total, number>>();
	expectType<Equal<typeof page.data.data, User[]>>();

	const created = await Stoli.post('users.store', { name: 'x', admin: true });
	expectType<Equal<typeof created.data, User>>();

	await Stoli.put('users.update', { user: 1, name: 'x' });
	await Stoli.post('users.store', new FormData());
	await Stoli.get('posts.index', {}, { headers: { 'X-Test': '1' } });
	await Stoli.get('versions.show', { version: 1 });
	await Stoli.delete('store.cart.remove', { product_id: 1 });

	// Untyped routes fall back to a plain record
	const cart = await Stoli.get('store.cart.show');
	expectType<Equal<typeof cart.data, Record<string, unknown>>>();

	// @ts-expect-error a POST route is not a GET route
	await Stoli.get('users.store');
	// @ts-expect-error a required parameter cannot be left out
	await Stoli.get('users.show');
	// @ts-expect-error nor be missing from the parameters
	await Stoli.get('users.show', {});
	// @ts-expect-error a numeric constraint takes no text
	await Stoli.get('users.show', { user: 'x' });
	// @ts-expect-error a whereIn constraint takes only its values
	await Stoli.get('kinds.show', { kind: 'c' });
	// @ts-expect-error the request Data type is checked
	await Stoli.post('users.store', { name: 1 });
	// @ts-expect-error and its required fields too
	await Stoli.put('users.update', { user: 1 });
}

// Constants keep their literal values
expectType<Equal<typeof Constants.LaravelStoli.Tests.Fixtures.Constants.Permission.VIEW, 'view'>>();

export { router };
