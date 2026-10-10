<?php

declare(strict_types=1);

use App\Http\Controllers\E2eController;
use Illuminate\Support\Facades\Route;

Route::get('users', [E2eController::class, 'index'])->name('users.index');
Route::post('users', [E2eController::class, 'store'])->name('users.store');
Route::get('users/wrapped', [E2eController::class, 'wrapped'])->name('users.wrapped');
Route::get('users/{user}', [E2eController::class, 'show'])->whereNumber('user')->name('users.show');
Route::put('users/{user}', [E2eController::class, 'update'])->whereNumber('user')->name('users.update');
Route::post('orders', [E2eController::class, 'order'])->name('orders.store');
Route::post('categories', [E2eController::class, 'category'])->name('categories.store');
Route::post('articles', [E2eController::class, 'article'])->name('articles.store');
Route::post('folders/{folder}/files', [E2eController::class, 'upload'])->name('files.store');
Route::put('folders/{folder}/files', [E2eController::class, 'upload'])->name('files.update');
Route::get('search/{page?}', [E2eController::class, 'search'])->name('search');
Route::get('kinds/{kind}', [E2eController::class, 'kind'])->whereIn('kind', ['a', 'b'])->name('kinds.show');
Route::delete('cart/{product}', [E2eController::class, 'remove'])->name('cart.remove');
Route::get('route-check', [E2eController::class, 'routeCheck'])->name('route-check');
