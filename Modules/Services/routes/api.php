<?php

use Illuminate\Support\Facades\Route;
use Modules\Services\Http\Controllers\ServiceController;

Route::middleware(['auth:api', 'tenant'])->prefix('services')->group(function () {
    Route::get('list', [ServiceController::class, 'handleListServices'])->middleware('permission:services.view');
    Route::get('{id}/detail', [ServiceController::class, 'handleGetService'])->middleware('permission:services.view');
    Route::post('create', [ServiceController::class, 'handleCreateService'])->middleware('permission:services.manage');
    Route::put('update/{id}', [ServiceController::class, 'handleUpdateService'])->middleware('permission:services.manage');
    Route::delete('delete/{id}', [ServiceController::class, 'handleDeleteService'])->middleware('permission:services.manage');
});
