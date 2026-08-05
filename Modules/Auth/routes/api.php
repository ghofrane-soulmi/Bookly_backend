<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\StaffController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');

    Route::middleware(['auth:api', 'tenant'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

Route::middleware(['auth:api', 'tenant'])->prefix('staff')->group(function () {
    Route::get('list', [StaffController::class, 'handleListStaff']);
    Route::get('{id}/detail', [StaffController::class, 'handleGetStaff']);
    Route::post('create', [StaffController::class, 'handleCreateStaff']);
    Route::put('update/{id}', [StaffController::class, 'handleUpdateStaff']);
    Route::delete('delete/{id}', [StaffController::class, 'handleDeleteStaff']);
});
