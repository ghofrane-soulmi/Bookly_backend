<?php

use Illuminate\Support\Facades\Route;
use Modules\Services\Http\Controllers\ServiceController;

Route::middleware(['auth:api', 'tenant'])->prefix('services')->group(function () {
    Route::get('list', [ServiceController::class, 'handleListServices']);
    Route::get('{id}/detail', [ServiceController::class, 'handleGetService']);
    Route::post('create', [ServiceController::class, 'handleCreateService']);
    Route::put('update/{id}', [ServiceController::class, 'handleUpdateService']);
    Route::delete('delete/{id}', [ServiceController::class, 'handleDeleteService']);
});
