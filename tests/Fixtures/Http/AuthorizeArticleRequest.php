<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A request that only authorizes, validating nothing.
 */
final class AuthorizeArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
