<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Resolvers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Layer 0: Resolve a route's response type by actually executing the controller
 * inside a database transaction that is rolled back afterwards.
 *
 * Only runs when:
 *  - The application environment is 'testing' (--env=testing was passed), AND
 *  - The --safe flag was NOT passed to stoli:generate, AND
 *  - The route responds to GET (other methods are unsafe to call without side effects).
 */
final readonly class ExecutionReturnTypeResolver
{
    use ReturnTypeResolverHelpers;

    public function __construct(private Container $container)
    {
    }

    public function resolve(LaravelRoute $route): ?array
    {
        if (!app()->environment('testing')) {
            return null;
        }

        if (app()->make('config')->get('stoli._safe', false)) {
            return null;
        }

        if (!in_array('GET', $route->methods() ?? [])) {
            return null;
        }

        $action = $route->getAction('uses');
        if (!is_string($action)) {
            return null;
        }

        $response       = null;
        $executionError = null;

        try {
            DB::beginTransaction();

            try {
                $uri = $this->buildUriWithSeededModels($route);

                $fakeRequest = Request::create('/' . $uri, 'GET');
                $fakeRequest->setRouteResolver(fn() => $route);

                $prevRequest = $this->container->make('request');
                $this->container->instance('request', $fakeRequest);
                $this->container->instance(Request::class, $fakeRequest);

                $prevUser = Auth::user();
                $this->loginWithSeededUser();

                try {
                    $response = $this->container->call($action);
                } finally {
                    $this->container->instance('request', $prevRequest);
                    $this->container->instance(Request::class, $prevRequest);
                    if ($prevUser !== null) {
                        Auth::setUser($prevUser);
                    } else {
                        Auth::logout();
                    }
                }
            } finally {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            $executionError = $e;
        }

        if ($executionError !== null) {
            $routeName = $route->getName() ?? $route->uri();
            $message   = $executionError->getMessage();
            trigger_error(
                "[stoli] Layer-0 execution failed for route \"{$routeName}\": {$message} — falling back to static analysis.",
                E_USER_WARNING,
            );
            return null;
        }

        $jsonResponse = self::toJsonResponse($response);
        if ($jsonResponse === null) {
            return null;
        }

        $decoded = json_decode($jsonResponse->getContent(), true);
        if (!is_array($decoded) || empty($decoded)) {
            return null;
        }

        $shape = [];
        foreach ($decoded as $key => $value) {
            $shape[(string) $key] = self::jsonValueToTypeString($value);
        }

        return ['wrap' => null, 'collection' => false, 'shape' => $shape];
    }

    private static function toJsonResponse(mixed $response): ?JsonResponse
    {
        if ($response instanceof JsonResponse) {
            return $response;
        }

        if ($response instanceof JsonResource) {
            $r = $response->toResponse(new Request());
            return $r instanceof JsonResponse ? $r : null;
        }

        if (class_exists('Spatie\\LaravelData\\Data') && $response instanceof \Spatie\LaravelData\Data) {
            $r = $response->toResponse(new Request());
            return $r instanceof JsonResponse ? $r : null;
        }

        return null;
    }

    /**
     * Seed model instances for every route URI parameter, bind them to the
     * route object, and return the resolved URI with their primary keys filled in.
     */
    private function buildUriWithSeededModels(LaravelRoute $route): string
    {
        $uri        = $route->uri();
        $paramTypes = $this->resolveParamModelClasses($route);

        preg_match_all('/\{(\w+)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $param    = $match[1];
            $optional = !empty($match[2]);
            $full     = $match[0];

            $key = 1;

            if (isset($paramTypes[$param])) {
                $instance = $this->seedModel($paramTypes[$param]);
                if ($instance !== null) {
                    $route->setParameter($param, $instance);
                    $key = $instance->getKey() ?? 1;
                }
            }

            $uri = str_replace($full, $optional ? '' : (string) $key, $uri);
        }

        return trim(preg_replace('#//+#', '/', $uri), '/');
    }

    /**
     * Map each URI parameter name to its Eloquent model class.
     *
     * @return array<string, class-string<Model>>
     */
    private function resolveParamModelClasses(LaravelRoute $route): array
    {
        $action = $route->getAction('uses');
        if (!is_string($action)) {
            return [];
        }

        [$controller, $method] = str_contains($action, '@')
            ? explode('@', $action, 2)
            : [$action, '__invoke'];

        try {
            $map = [];

            foreach ((new ReflectionMethod($controller, $method))->getParameters() as $param) {
                $type = $param->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $class = $type->getName();

                if (class_exists($class) && is_subclass_of($class, Model::class)) {
                    $map[$param->getName()] = $class;
                }
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    private function seedModel(string $modelClass): ?Model
    {
        if (method_exists($modelClass, 'factory')) {
            try {
                return $modelClass::factory()->create();
            } catch (Throwable) {
            }
        }

        try {
            /** @var Model $instance */
            $instance   = new $modelClass();
            $table      = $instance->getTable();
            $attributes = [];

            foreach (Schema::getColumns($table) as $column) {
                $name = $column['name'];

                if ($name === $instance->getKeyName() && ($column['auto_increment'] ?? false)) {
                    continue;
                }

                if ($column['nullable'] ?? false) {
                    continue;
                }

                $attributes[$name] = self::dummyValueForColumn($column);
            }

            $instance->fill($attributes);
            $instance->save();

            return $instance;
        } catch (Throwable) {
        }

        return null;
    }

    private static function dummyValueForColumn(array $column): mixed
    {
        $type = strtolower($column['type_name'] ?? $column['type'] ?? 'varchar');

        return match (true) {
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'])   => 1,
            in_array($type, ['float', 'double', 'decimal', 'numeric', 'real'])                  => 1.0,
            in_array($type, ['bool', 'boolean'])                                                => true,
            in_array($type, ['json', 'jsonb'])                                                  => '{}',
            in_array($type, ['date'])                                                           => now()->toDateString(),
            in_array($type, ['datetime', 'timestamp'])                                          => now()->toDateTimeString(),
            str_contains($type, 'char') || str_contains($type, 'text')                         => 'test',
            default                                                                             => 'test',
        };
    }

    private function loginWithSeededUser(): void
    {
        try {
            $userModel = config('auth.providers.users.model', \App\Models\User::class);

            if (!class_exists($userModel)) {
                return;
            }

            $user = method_exists($userModel, 'factory')
                ? $userModel::factory()->create()
                : $userModel::first();

            if ($user !== null) {
                Auth::setUser($user);
            }
        } catch (Throwable) {
        }
    }

    private static function jsonValueToTypeString(mixed $value): string
    {
        return match (true) {
            $value === null                                        => 'unknown',
            is_bool($value)                                       => 'boolean',
            is_int($value) || is_float($value)                    => 'number',
            is_string($value)                                     => 'string',
            is_array($value) && empty($value)                     => 'unknown[]',
            is_array($value) && array_is_list($value)             => self::jsonValueToTypeString($value[0]) . '[]',
            is_array($value)                                      => self::jsonObjectToTypeString($value),
            default                                               => 'unknown',
        };
    }

    private static function jsonObjectToTypeString(array $object): string
    {
        $parts = [];

        foreach ($object as $key => $value) {
            $parts[] = "$key: " . self::jsonValueToTypeString($value);
        }

        return '{ ' . implode('; ', $parts) . ' }';
    }
}
