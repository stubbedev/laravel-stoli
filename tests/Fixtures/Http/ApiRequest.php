<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The base the application's requests extend.
 */
abstract class ApiRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['locale' => 'required|in:en,da'];
    }
}
