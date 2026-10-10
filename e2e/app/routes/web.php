<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('pages/{slug}', static fn (string $slug): string => $slug)->name('pages.show');
