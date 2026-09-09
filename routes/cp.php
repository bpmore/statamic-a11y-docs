<?php

declare(strict_types=1);

use Bpmore\StatamicA11yDocs\Http\Controllers\DashboardController;
use Bpmore\StatamicA11yDocs\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Route;

// Same idiom Statamic core uses for its own control panel routes.
Route::prefix('a11y-docs')->name('a11y-docs.')->middleware('can:view document checks')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('queue', [QueueController::class, 'index'])->name('queue');
});
