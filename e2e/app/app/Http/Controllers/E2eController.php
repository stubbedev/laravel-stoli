<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ApiResponseData;
use App\Data\CategoryData;
use App\Data\OrderData;
use App\Data\StoreUserData;
use App\Data\UserData;
use App\Enums\Status;
use App\Http\Requests\StoreArticleRequest;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The routes the generated clients are tested against, end to end.
 */
final class E2eController
{
    public function show(int $user): UserData
    {
        return new UserData($user, "user {$user}");
    }

    public function store(StoreUserData $data): UserData
    {
        return new UserData(1, $data->name.($data->admin ? ' (admin)' : ''));
    }

    public function update(int $user, StoreUserData $data): UserData
    {
        return new UserData($user, $data->name);
    }

    /**
     * @return PaginatedDataCollection<int, UserData>
     */
    public function index(Request $request): PaginatedDataCollection
    {
        $users = [new UserData(1, 'a'), new UserData(2, 'b')];

        return UserData::collect(new LengthAwarePaginator($users, 2, 15, (int) $request->query('page', '1')), PaginatedDataCollection::class);
    }

    /**
     * @return ApiResponseData<UserData>
     */
    public function wrapped(): ApiResponseData
    {
        return new ApiResponseData(new UserData(7, 'wrapped'));
    }

    public function order(OrderData $order): OrderData
    {
        return $order;
    }

    public function category(CategoryData $category): CategoryData
    {
        return $category;
    }

    /**
     * @return array{title: string, authors: int, locale: string}
     */
    public function article(StoreArticleRequest $request): array
    {
        return [
            'title' => (string) $request->validated('title'),
            'authors' => count((array) $request->validated('authors')),
            'locale' => (string) $request->validated('locale'),
        ];
    }

    /**
     * @return array{folder: int, name: string, size: int, method: string, tags: list<string>, flag: bool}
     */
    public function upload(Request $request, int $folder): array
    {
        $file = $request->file('file');

        return [
            'folder' => $folder,
            'name' => $file === null || is_array($file) ? '' : $file->getClientOriginalName(),
            'size' => $file === null || is_array($file) ? 0 : (int) $file->getSize(),
            'method' => $request->method(),
            'tags' => array_values(array_map('strval', (array) $request->input('meta.tags', []))),
            'flag' => $request->boolean('meta.flag'),
        ];
    }

    /**
     * @return array{query: array<string, mixed>}
     */
    public function search(Request $request): array
    {
        return ['query' => $request->query()];
    }

    public function kind(string $kind): Status
    {
        return Status::Active;
    }

    public function remove(int $product): void {}

    /**
     * What Laravel's own route() builds, to compare the generated URLs against.
     *
     * @return array{url: string}
     */
    public function routeCheck(Request $request): array
    {
        $parameters = json_decode((string) $request->query('parameters', '{}'), true);

        return ['url' => route((string) $request->query('name'), is_array($parameters) ? $parameters : [])];
    }
}
