// Stands in for the typescript:transform output of the fixture Data classes, in the
// `declare namespace` form the GlobalNamespaceWriter writes.
declare namespace StubbeDev.LaravelStoli.Tests.Fixtures.TypeScript.Data {
	export type UserData = { id: number; name: string };
	export type StoreUserData = { name: string; admin?: boolean };
	export type ApiResponseData<TData> = { data: TData };
	export type WrappedUserData = { id: number };
}
