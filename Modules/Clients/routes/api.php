<?php

use Illuminate\Support\Facades\Route;
use Modules\Clients\Http\Controllers\ClientController;

Route::middleware(['auth:api', 'tenant'])->prefix('clients')->group(function () {
    Route::get('list', [ClientController::class, 'handleListClients'])->middleware('permission:clients.view');
    Route::get('{id}/detail', [ClientController::class, 'handleGetClient'])->middleware('permission:clients.view');
    Route::post('create', [ClientController::class, 'handleCreateClient'])->middleware('permission:clients.manage');
    Route::put('update/{id}', [ClientController::class, 'handleUpdateClient'])->middleware('permission:clients.manage');
    Route::delete('delete/{id}', [ClientController::class, 'handleDeleteClient'])->middleware('permission:clients.manage');
});
