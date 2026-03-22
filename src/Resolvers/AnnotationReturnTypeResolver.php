<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Resolvers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Layer 1: Resolve a route's response type by inspecting the controller method's
 * PHP return type annotation.
 *
 * Handles:
 *  - JsonResource subclasses (single resource)
 *  - ResourceCollection subclasses (collection)
 *  - Spatie\LaravelData\Data subclasses
 */
final readonly class AnnotationReturnTypeResolver
{
    use ReturnTypeResolverHelpers;

    public function __construct(private Container $container)
    {
    }

    public function resolve(ReflectionMethod $method): ?array
    {
        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType) {
            return null;
        }

        $className = $returnType->getName();

        if (!class_exists($className) || $className === AnonymousResourceCollection::class) {
            return null;
        }

        try {
            if ((new ReflectionClass($className))->isAbstract()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        if (is_subclass_of($className, ResourceCollection::class)) {
            return $this->resolveViaCollectionClass($className);
        }

        if (is_subclass_of($className, JsonResource::class)) {
            return $this->resolveSingle($className);
        }

        if ($this->isDataClass($className)) {
            return $this->resolveDataClass($className, false);
        }

        return null;
    }
}
