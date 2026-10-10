<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Http;

use Illuminate\Validation\Rule;
use StubbeDev\LaravelStoli\Tests\Fixtures\TypeScript\Data\Status;

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
            'published' => 'boolean',
            'tags' => 'array',
            'tags.*' => 'string',
            'meta.description' => 'nullable|string',
            'authors' => 'required|array',
            'authors.*.name' => 'required|string',
            'authors.*.email' => 'email',
            'cover' => 'sometimes|image',
            'password' => 'required|confirmed',
            'reference' => 'uuid',
            'internal' => 'prohibited',
            'editor' => Rule::requiredIf(fn (): bool => true),
        ];
    }
}
