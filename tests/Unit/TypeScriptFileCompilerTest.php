<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StubbeDev\LaravelStoli\Compilers\TypeScriptFileCompiler;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\Items\File;
use StubbeDev\LaravelStoli\Items\Parameter;
use StubbeDev\LaravelStoli\Items\Route;

final class TypeScriptFileCompilerTest extends TestCase
{
    /**
     * @param  list<Route>  $routes
     */
    private static function compile(array $routes, string $name = 'api'): string
    {
        return (new TypeScriptFileCompiler)->compile(new File($name, '/app/resources/routes', '/app/resources/types', $routes));
    }

    public function test_it_compiles_the_definitions_the_aliases_and_the_routes(): void
    {
        $user = DataType::imported('UserData', '/app/resources/types/index.ts');

        $output = self::compile([
            new Route('users.show', 'api/users/{user}', 'https://app.test', parameters: [new Parameter('user', 'number', true)], methods: ['get'], response: $user, status: '200'),
            new Route('users.store', 'api/users', 'https://app.test', methods: ['post'], request: DataType::declared('StoreUserDataInput', new DataType('export interface StoreUserDataInput extends StoreUserData {}', ['/app/resources/types/index.ts' => ['StoreUserData']])), response: $user, status: '201'),
            new Route('posts.index', 'posts/{page?}', methods: ['get', 'delete'], parameters: [new Parameter('page', 'string | number', false)]),
            new Route('tenant.home', 'home', 'https://{account}.app.test', domain: true, parameters: [new Parameter('account', 'string', true)]),
        ]);

        self::assertSame(<<<'TS'
        import type { NamesFor, ParamsOf, ResponsesOf, RouteMap, StatusesOf } from '../types/stoli';
        import type { UserData, StoreUserData } from '../types/index';

        export interface StoreUserDataInput extends StoreUserData {}

        export type ApiRoutes = {
        	'users.show': {
        		params: { user: number; [key: string]: unknown },
        		response: UserData,
        		status: 200,
        		methods: 'get',
        	},
        	'users.store': {
        		params: StoreUserDataInput,
        		response: UserData,
        		status: 201,
        		methods: 'post',
        	},
        	'posts.index': {
        		params: { page?: string | number | null; [key: string]: unknown },
        		response: unknown,
        		status: number,
        		methods: 'get' | 'delete',
        	},
        	'tenant.home': {
        		params: { account: string; [key: string]: unknown },
        		response: unknown,
        		status: number,
        		methods: never,
        	},
        };

        export type ApiRouteName = keyof ApiRoutes;
        export type ApiRouteParams = ParamsOf<ApiRoutes>;
        export type ApiRouteResponse = ResponsesOf<ApiRoutes>;
        export type ApiRouteStatus = StatusesOf<ApiRoutes>;
        export type ApiGetRouteName = NamesFor<ApiRoutes, 'get'>;
        export type ApiPostRouteName = NamesFor<ApiRoutes, 'post'>;
        export type ApiPutRouteName = NamesFor<ApiRoutes, 'put'>;
        export type ApiPatchRouteName = NamesFor<ApiRoutes, 'patch'>;
        export type ApiDeleteRouteName = NamesFor<ApiRoutes, 'delete'>;

        const routes: RouteMap<ApiRoutes> = {
        	'users.show': {
        		host: 'https://app.test',
        		uri: 'api/users/{user}',
        	},
        	'users.store': {
        		host: 'https://app.test',
        		uri: 'api/users',
        	},
        	'posts.index': {
        		host: null,
        		uri: 'posts/{page?}',
        	},
        	'tenant.home': {
        		host: 'https://{account}.app.test',
        		uri: 'home',
        		domain: true,
        	},
        };

        export default routes;

        TS, $output);
    }

    public function test_a_data_request_type_is_intersected_with_the_path_parameters_and_closes_the_params(): void
    {
        $output = self::compile([
            new Route('users.update', 'users/{user}', parameters: [new Parameter('user', 'number', true)], methods: ['put'], request: new DataType('App.Data.StoreUserData')),
        ]);

        self::assertStringContainsString('params: { user: number } & App.Data.StoreUserData,', $output);
    }

    public function test_ambient_types_need_no_import(): void
    {
        $output = self::compile([new Route('users.show', 'users', response: new DataType('App.Data.UserData'))]);

        self::assertStringContainsString('response: App.Data.UserData,', $output);
        self::assertStringNotContainsString('App.Data', explode("\n\n", $output)[0]);
    }

    public function test_the_route_service_types_are_imported_from_where_it_is_written(): void
    {
        $output = self::compile([new Route('users.index', 'users', response: DataType::runtime('Paginated')->withArguments(new DataType('User')))]);

        self::assertStringStartsWith("import type { NamesFor, ParamsOf, ResponsesOf, RouteMap, StatusesOf, Paginated } from '../types/stoli';", $output);
    }

    public function test_the_module_name_is_studly_cased(): void
    {
        self::assertStringContainsString('export type AdminPanelRoutes = {};', self::compile([], 'admin-panel'));
    }

    public function test_a_numeric_route_name_keeps_its_name(): void
    {
        self::assertStringContainsString("'404': {", self::compile([new Route('404', 'missing')]));
    }

    public function test_a_file_without_routes_compiles_to_an_empty_object(): void
    {
        self::assertStringContainsString('const routes: RouteMap<ApiRoutes> = {};', self::compile([]));
    }
}
