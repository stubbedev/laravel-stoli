<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript;

use LogicException;
use Spatie\LaravelData\{CursorPaginatedDataCollection, DataCollection, PaginatedDataCollection};
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data\{ApiResponseData, StoreUserData, UserData as User};

/**
 * Only reflected on: the Data types come from the signatures and the @return tags, which
 * name their classes through a grouped, aliased import on purpose.
 */
final class UserController
{
    public function show(int $user): User
    {
        throw new LogicException('not called');
    }

    public function store(StoreUserData $data): User
    {
        throw new LogicException('not called');
    }

    public function update(int $user, StoreUserData $data): User
    {
        throw new LogicException('not called');
    }

    /**
     * @return DataCollection<int, User>
     */
    public function index(): DataCollection
    {
        throw new LogicException('not called');
    }

    /**
     * @return PaginatedDataCollection<int, User>
     */
    public function paginated(): PaginatedDataCollection
    {
        throw new LogicException('not called');
    }

    /**
     * @return CursorPaginatedDataCollection<int, User>
     */
    public function cursor(): CursorPaginatedDataCollection
    {
        throw new LogicException('not called');
    }

    /**
     * @return ApiResponseData<User>
     */
    public function wrapped(): ApiResponseData
    {
        throw new LogicException('not called');
    }

    /**
     * @return list<User>
     */
    public function list(): array
    {
        throw new LogicException('not called');
    }
}
