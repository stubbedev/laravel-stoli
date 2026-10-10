/**
 * Compiled with tsc against what stoli:generate wrote into ./build, once per client, see
 * tests/Integration/GeneratedTypeScriptTest.php. Nothing runs: the checks are the types.
 *
 * Every `@ts-expect-error` is a call that must be rejected; tsc fails when one of them
 * type-checks after all, so a type that loosens is caught as surely as one that breaks.
 */
import { createRoute, RouteService, type CursorPaginated, type Paginated } from './build/stoli';
import routes, {
	type ApiDeleteRouteName,
	type ApiRouteStatus,
	type CategoryDataInput,
	type OrderDataInput,
	type StoreArticleRequestInput,
	type ApiGetRouteName,
	type ApiPostRouteName,
	type ApiRouteName,
	type ApiRouteParams,
	type ApiRouteResponse,
} from './build/api';
import { Stoli } from './build/router';
import { usersShow, postsIndex, tenantDashboard } from './build/api.urls';
import { StubbeDev as Constants } from './build/constants';

type RequiredKeys<T> = { [K in keyof T]-?: {} extends Pick<T, K> ? never : K }[keyof T];

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends (<T>() => T extends B ? 1 : 2) ? true : false;

function expectType<T extends true>(): void {}

type User = StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data.UserData;
type Status = StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data.Status;
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

// Without a constraint, the action's signature narrows: a backed enum to its values, an int to a number
expectType<Equal<ApiRouteParams['statuses.show']['status'], 'active' | 'archived'>>();
expectType<Equal<ApiRouteParams['statuses.show']['page'], number>>();

// A bound model takes the type of its key: an integer id, or the text of a slug or binding field
expectType<Equal<ApiRouteParams['bound.show']['post'], number>>();
expectType<Equal<ApiRouteParams['bound.show']['article'], string>>();
expectType<Equal<ApiRouteParams['bound.show']['byTitle'], string>>();

// A request Data object is typed as it is taken in: input names, defaults and nullables optional, computed left out
expectType<
	Equal<
		ApiRouteParams['profile.update'],
		{ display_name: string; email: string; bio?: string | null; website?: string; public?: boolean; password?: string | null }
	>
>();

// Nested Data objects are taken in as their own input types, however deep, cycles included
type Uuid = `${string}-${string}-${string}-${string}-${string}`;
expectType<Equal<ApiRouteParams['orders.store'], OrderDataInput>>();
expectType<Equal<OrderDataInput['shipping'], { street_name: string; city: string; zip?: string | null }>>();
expectType<Equal<OrderDataInput['billing'], { street_name: string; city: string; zip?: string | null } | null | undefined>>();
expectType<Equal<OrderDataInput['lines'], { sku: string; quantity: number }[]>>();
expectType<Equal<CategoryDataInput['children'], CategoryDataInput[] | undefined>>();
expectType<Equal<NonNullable<CategoryDataInput['parent']>['children'], CategoryDataInput[] | undefined>>();

// Validation attributes narrow what a property takes in, and whether it may be left out
expectType<Equal<OrderDataInput['state'], 'draft' | 'placed'>>();
expectType<Equal<OrderDataInput['status'], 'active' | 'archived'>>();
expectType<Equal<OrderDataInput['reference'], Uuid>>();
expectType<Equal<OrderDataInput['terms'], true | 1 | '1' | 'yes' | 'on' | 'true'>>();
// `required` fails on null, so a nullable property it guards takes none
expectType<Equal<OrderDataInput['note'], string>>();
// `sometimes` lets validation skip it, but without a default the object cannot be built without it
expectType<Equal<OrderDataInput['coupon'], string>>();
expectType<Equal<OrderDataInput['password_confirmation'], string>>();
expectType<Equal<'internal' extends keyof OrderDataInput ? true : false, false>>();
expectType<Equal<'note' extends RequiredKeys<OrderDataInput> ? true : false, true>>();

// A FormRequest takes in what its rules() say, its parent's rules included
expectType<Equal<ApiRouteParams['articles.store'], StoreArticleRequestInput>>();
expectType<
	Equal<
		StoreArticleRequestInput,
		{
			locale: 'en' | 'da';
			title: string;
			status: 'active' | 'archived';
			kind: 'post' | 'page';
			priority?: 1 | 2 | 3 | null;
			published?: boolean | 0 | 1 | '0' | '1';
			tags?: string[];
			meta?: { description?: string | null };
			authors: { name: string; email?: string }[];
			cover?: Blob;
			password: unknown;
			password_confirmation: unknown;
			reference?: Uuid;
			editor?: unknown;
		}
	>
>();

// The status a route succeeds with
expectType<Equal<ApiRouteStatus['users.show'], 200>>();
expectType<Equal<ApiRouteStatus['users.store'], 201>>();
expectType<Equal<ApiRouteStatus['shapes.nothing'], 200>>();
expectType<Equal<ApiRouteStatus['users.selfResponding'], number>>();
expectType<Equal<ApiRouteStatus['store.cart.show'], number>>();

// Per-route URL functions, typed by the same definitions
expectType<Equal<ReturnType<typeof usersShow>, string>>();
usersShow({ user: 1 });
postsIndex();
tenantDashboard({ account: 'acme' });
// @ts-expect-error a numeric constraint takes no text here either
usersShow({ user: 'x' });
// @ts-expect-error nor may a required parameter be left out
usersShow();

// Responses resolve per route, through collections, pagination and nested generics
expectType<Equal<ApiRouteResponse['users.show'], User>>();
expectType<Equal<ApiRouteResponse['users.index'], User[]>>();
expectType<Equal<ApiRouteResponse['users.list'], User[]>>();
expectType<Equal<ApiRouteResponse['users.paginated'], Paginated<User>>>();
expectType<Equal<ApiRouteResponse['users.cursor'], CursorPaginated<User>>>();
expectType<Equal<ApiRouteResponse['users.wrapped'], Wrapped<User>>>();
expectType<Equal<ApiRouteResponse['shapes.wrappedNull'], Wrapped<null>>>();
expectType<Equal<ApiRouteResponse['shapes.wrappedEnum'], Wrapped<Status>>>();
expectType<Equal<ApiRouteResponse['shapes.wrappedNested'], Wrapped<User[] | null>>>();
expectType<Equal<ApiRouteResponse['shapes.wrappedUntagged'], Wrapped<unknown>>>();
expectType<Equal<ApiRouteResponse['shapes.keyed'], Record<string, User>>>();
expectType<Equal<ApiRouteResponse['shapes.collection'], User[]>>();
expectType<Equal<ApiRouteResponse['shapes.shape'], { user: User; total?: number; kind: 'a' | 'b' }>>();
expectType<Equal<ApiRouteResponse['shapes.maybe'], User | null>>();
expectType<Equal<ApiRouteResponse['shapes.nothing'], void>>();

// A route without a resolvable response says so instead of claiming an object
expectType<Equal<ApiRouteResponse['store.cart.show'], unknown>>();

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

// The standalone route() function and the service are typed by the same definitions
const route = createRoute({ routes });
route('users.show', { user: 1 });
route('posts.index');
route('posts.index', { page: null });
route('tenant.dashboard', { account: 'acme', tab: 'x' });
// @ts-expect-error unknown route
route('nope');
// @ts-expect-error a required parameter cannot be left out
route('users.show');
// @ts-expect-error nor be missing from the parameters
route('users.show', {});
// @ts-expect-error a numeric constraint takes no text, in route() as in the router
route('users.show', { user: 'x' });
// @ts-expect-error a domain parameter is required as well
route('tenant.dashboard');
// @ts-expect-error the request Data type is checked by route() too
route('users.store', { name: 1 });
// @ts-expect-error and with a Data type, a misspelled field is not a query parameter
route('users.store', { name: 'x', nmae: 'y' });

const service = new RouteService({ routes });
const { url, rest } = service.resolve('users.update', { user: 1, name: 'x', admin: true });
expectType<Equal<typeof url, string>>();
expectType<Equal<typeof rest, Record<string, unknown>>>();

// has() narrows a string to a route name
const name: string = 'users.index';
if (service.has(name)) {
	expectType<Equal<typeof name, ApiRouteName>>();
}

async function router(): Promise<void> {
	const user = await Stoli.get('users.show', { user: 1 });
	expectType<Equal<typeof user.data, User>>();

	const page = await Stoli.get('users.paginated');
	expectType<Equal<typeof page.data.meta.total, number>>();
	expectType<Equal<typeof page.data.data, User[]>>();

	const created = await Stoli.post('users.store', { name: 'x', admin: true });
	expectType<Equal<typeof created.data, User>>();
	expectType<Equal<typeof created.status, 201>>();
	expectType<Equal<typeof user.status, 200>>();

	try {
		await Stoli.post('orders.store', {} as OrderDataInput);
	} catch (error) {
		const failed = Stoli.validationErrors('orders.store', error);
		expectType<Equal<typeof failed extends null ? true : false, false>>();
		expectType<Equal<NonNullable<typeof failed>['message'], string>>();
		expectType<Equal<NonNullable<typeof failed>['errors']['shipping.street_name'], string[] | undefined>>();
		expectType<Equal<NonNullable<typeof failed>['errors']['lines.0.sku'], string[] | undefined>>();
		expectType<Equal<NonNullable<typeof failed>['errors']['password_confirmation'], string[] | undefined>>();
		// @ts-expect-error an error is reported under the input name, not the output name
		failed?.errors['shipping.street'];
		// @ts-expect-error and only under a field the route takes
		failed?.errors['nope'];
	}

	await Stoli.put('users.update', { user: 1, name: 'x', admin: false });
	await Stoli.post('users.store', new FormData());
	await Stoli.get('posts.index', {}, { headers: { 'X-Test': '1' } });
	await Stoli.get('versions.show', { version: 1 });
	await Stoli.delete('store.cart.remove', { product_id: 1 });

	// Untyped routes respond with unknown, which has to be narrowed before use
	const cart = await Stoli.get('store.cart.show');
	expectType<Equal<typeof cart.data, unknown>>();

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
	// @ts-expect-error a request is read by its input names, not its output names
	await Stoli.put('profile.update', { displayName: 'x', email: 'x' });
	// @ts-expect-error a computed property is not taken
	await Stoli.put('profile.update', { display_name: 'x', email: 'x', initials: 'x' });
	// @ts-expect-error an enum parameter takes only its values
	await Stoli.get('statuses.show', { status: 'gone', page: 1 });

	await Stoli.put('profile.update', { display_name: 'x', email: 'x' });
	await Stoli.get('statuses.show', { status: 'active', page: 2 });
}

// Constants keep their literal values
expectType<Equal<typeof Constants.LaravelStoli.Tests.Fixtures.Constants.Permission.VIEW, 'view'>>();

export { router };
