<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:api', 'tenant'])->prefix('dashboard')->name('dashboard.')->group(function () {
    Route::get('summary', [DashboardController::class, 'summary'])->name('summary');
});
