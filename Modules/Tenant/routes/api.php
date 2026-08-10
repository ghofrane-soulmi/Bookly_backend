<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenant\Http\Controllers\BusinessController;

Route::middleware(['auth:api', 'tenant'])->prefix('business')->group(function () {
    Route::get('detail', [BusinessController::class, 'handleGetBusiness']);
    Route::put('update', [BusinessController::class, 'handleUpdateBusiness']);
});
