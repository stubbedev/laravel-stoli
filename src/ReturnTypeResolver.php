<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route as LaravelRoute;
use ReflectionMethod;
use StubbeDev\LaravelStoli\Resolvers\AnnotationReturnTypeResolver;
use StubbeDev\LaravelStoli\Resolvers\ExecutionReturnTypeResolver;
use StubbeDev\LaravelStoli\Resolvers\TokenReturnTypeResolver;
use Throwable;

/**
 * Coordinator that attempts to resolve a controller action's response type
 * using three layers, each falling through to the next on failure:
 *
 *  Layer 0 — ExecutionReturnTypeResolver:
 *    Actually calls the controller (only in --env=testing, GET routes only).
 *
 *  Layer 1 — AnnotationReturnTypeResolver:
 *    Reads the PHP return-type annotation on the controller method.
 *
 *  Layer 2 — TokenReturnTypeResolver:
 *    Tokenises the method body and pattern-matches return statements.
 */
final readonly class ReturnTypeResolver
{
    private ExecutionReturnTypeResolver  $executionResolver;
    private AnnotationReturnTypeResolver $annotationResolver;
    private TokenReturnTypeResolver      $tokenResolver;

    public function __construct(Container $container)
    {
        $this->executionResolver  = new ExecutionReturnTypeResolver($container);
        $this->annotationResolver = new AnnotationReturnTypeResolver($container);
        $this->tokenResolver      = new TokenReturnTypeResolver($container);
    }

    /**
     * @return array{wrap: string|null, collection: bool, shape: array<string, string>}|null
     */
    public function resolve(LaravelRoute $route): ?array
    {
        $action = $route->getAction('uses');

        if (!is_string($action)) {
            return null;
        }

        if (str_contains($action, '@')) {
            [$controller, $method] = explode('@', $action, 2);
        } else {
            $controller = $action;
            $method     = '__invoke';
        }

        if (!class_exists($controller)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (Throwable) {
            return null;
        }

        return $this->executionResolver->resolve($route)
            ?? $this->annotationResolver->resolve($reflection)
            ?? $this->tokenResolver->resolve($reflection);
    }
}
