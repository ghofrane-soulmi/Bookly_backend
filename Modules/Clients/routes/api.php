<?php

use Illuminate\Support\Facades\Route;
use Modules\Clients\Http\Controllers\ClientController;

Route::middleware(['auth:api', 'tenant'])->prefix('clients')->group(function () {
    Route::get('list', [ClientController::class, 'handleListClients']);
    Route::get('{id}/detail', [ClientController::class, 'handleGetClient']);
    Route::post('create', [ClientController::class, 'handleCreateClient']);
    Route::put('update/{id}', [ClientController::class, 'handleUpdateClient']);
    Route::delete('delete/{id}', [ClientController::class, 'handleDeleteClient']);
});
