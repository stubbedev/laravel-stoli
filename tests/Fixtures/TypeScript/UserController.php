<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript;

use Illuminate\Support\Collection;
use LogicException;
use StubbeDev\LaravelStoli\Tests\Fixtures\Http\StoreArticleRequest;
use StubbeDev\LaravelStoli\Tests\Fixtures\Models\{Article, Post};
use Spatie\LaravelData\{CursorPaginatedDataCollection, DataCollection, PaginatedDataCollection};
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data\{ApiResponseData, CategoryData, OrderData, ProfileData, SelfRespondingUserData, Status, StoreUserData, UserData as User, WrappedUserData};

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

    public function wrappedByClass(): WrappedUserData
    {
        throw new LogicException('not called');
    }

    public function selfResponding(): SelfRespondingUserData
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

    /**
     * @return ApiResponseData<null>
     */
    public function wrappedNull(): ApiResponseData
    {
        throw new LogicException('not called');
    }

    /**
     * @return ApiResponseData<Status>
     */
    public function wrappedEnum(): ApiResponseData
    {
        throw new LogicException('not called');
    }

    /**
     * @return ApiResponseData<list<User>|null>
     */
    public function wrappedNested(): ApiResponseData
    {
        throw new LogicException('not called');
    }

    public function wrappedUntagged(): ApiResponseData
    {
        throw new LogicException('not called');
    }

    /**
     * @return array<string, User>
     */
    public function keyed(): array
    {
        throw new LogicException('not called');
    }

    /**
     * @return Collection<int, User>
     */
    public function collection(): Collection
    {
        throw new LogicException('not called');
    }

    /**
     * @return array{user: User, total?: int, 'kind': 'a'|'b'}
     */
    public function shape(): array
    {
        throw new LogicException('not called');
    }

    public function maybe(): ?User
    {
        throw new LogicException('not called');
    }

    public function nothing(): void
    {
        throw new LogicException('not called');
    }

    /**
     * @return static
     */
    public function untransformed(): self
    {
        throw new LogicException('not called');
    }

    public function profile(ProfileData $profile): ProfileData
    {
        throw new LogicException('not called');
    }

    public function byStatus(Status $status, int $page): User
    {
        throw new LogicException('not called');
    }

    public function bound(Post $post, Article $article, Post $byTitle): User
    {
        throw new LogicException('not called');
    }

    public function order(OrderData $order): OrderData
    {
        throw new LogicException('not called');
    }

    public function category(CategoryData $category): CategoryData
    {
        throw new LogicException('not called');
    }

    public function article(StoreArticleRequest $request): void
    {
        throw new LogicException('not called');
    }
}
