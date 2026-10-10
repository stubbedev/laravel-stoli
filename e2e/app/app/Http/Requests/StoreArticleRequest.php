<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Status;
use Illuminate\Validation\Rule;

final class StoreArticleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'title' => 'required|string|max:255',
            'status' => ['required', Rule::enum(Status::class)],
            'kind' => ['required', Rule::in(['post', 'page'])],
            'priority' => 'nullable|integer|in:1,2,3',
            'tags' => 'array',
            'tags.*' => 'string',
            'authors' => 'required|array',
            'authors.*.name' => 'required|string',
            'authors.*.email' => 'email',
            'password' => 'required|confirmed',
        ];
    }
}
