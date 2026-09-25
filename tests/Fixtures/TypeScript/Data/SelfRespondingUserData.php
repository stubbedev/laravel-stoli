<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data;

use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Data;

/**
 * Sends itself unwrapped, whatever the data.wrap config says.
 */
final class SelfRespondingUserData extends Data
{
    public function __construct(
        public int $id,
    ) {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse($this->toArray());
    }
}
