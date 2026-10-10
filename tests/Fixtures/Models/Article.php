<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bound by its slug.
 */
final class Article extends Model
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
