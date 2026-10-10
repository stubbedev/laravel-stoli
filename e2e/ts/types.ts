/**
 * The type contract every e2e scenario is held to, whatever the writer: it names no
 * generated namespace, only what the route files export.
 */
import { createRoute } from '../resources/js/generated/stoli';
import routes, {
	type ApiRouteParams,
	type ApiRouteResponse,
	type ApiRouteStatus,
	type ApiGetRouteName,
	type CategoryDataInput,
	type OrderDataInput,
	type StoreArticleRequestInput,
	type StoreUserDataInput,
} from '../resources/js/generated/api';
import pages from '../resources/js/generated/pages';
import { App } from '../resources/js/generated/constants';

type Equal<A, B> = (<T>() => T extends A ? 1 : 2) extends (<T>() => T extends B ? 1 : 2) ? true : false;

export function expectType<T extends true>(): void {}

type Uuid = `${string}-${string}-${string}-${string}-${string}`;

expectType<Equal<ApiRouteParams['users.show']['user'], number>>();
expectType<Equal<ApiRouteParams['kinds.show']['kind'], 'a' | 'b'>>();
expectType<Equal<ApiRouteParams['users.store'], StoreUserDataInput>>();
expectType<Equal<StoreUserDataInput, { display_name: string; admin?: boolean }>>();
expectType<Equal<ApiRouteResponse['users.show']['name'], string>>();
expectType<Equal<ApiRouteResponse['users.index']['meta']['total'], number>>();
expectType<Equal<ApiRouteResponse['users.wrapped']['data']['id'], number>>();
expectType<Equal<ApiRouteResponse['cart.remove'], void>>();
expectType<Equal<ApiRouteStatus['users.store'], 201>>();
expectType<Equal<ApiRouteStatus['users.show'], 200>>();
expectType<Equal<'users.store' extends ApiGetRouteName ? true : false, false>>();

expectType<Equal<OrderDataInput['shipping'], { street_name: string; city: string; zip?: string | null }>>();
expectType<Equal<OrderDataInput['lines'], { sku: string; quantity: number }[]>>();
expectType<Equal<OrderDataInput['state'], 'draft' | 'placed'>>();
expectType<Equal<OrderDataInput['status'], 'active' | 'archived'>>();
expectType<Equal<OrderDataInput['reference'], Uuid>>();
expectType<Equal<OrderDataInput['password_confirmation'], string>>();
expectType<Equal<'internal' extends keyof OrderDataInput ? true : false, false>>();
expectType<Equal<CategoryDataInput['children'], CategoryDataInput[] | undefined>>();
expectType<Equal<StoreArticleRequestInput['locale'], 'en' | 'da'>>();
expectType<Equal<StoreArticleRequestInput['authors'], { name: string; email?: string }[]>>();

expectType<Equal<typeof App.Support.Permission.VIEW, 'view'>>();

const route = createRoute({ routes });
route('users.show', { user: 1 });
// @ts-expect-error a numeric constraint takes no text
route('users.show', { user: 'x' });
// @ts-expect-error unknown route
route('nope');
createRoute({ routes: pages })('pages.show', { slug: 'about' });
