<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\PermissionController;
use Modules\Auth\Http\Controllers\RoleController;
use Modules\Auth\Http\Controllers\StaffController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');

    Route::middleware(['auth:api', 'tenant'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

Route::middleware(['auth:api', 'tenant'])->prefix('staff')->group(function () {
    Route::get('list', [StaffController::class, 'handleListStaff'])->middleware('permission:staff.view');
    Route::get('{id}/detail', [StaffController::class, 'handleGetStaff'])->middleware('permission:staff.view');
    Route::post('create', [StaffController::class, 'handleCreateStaff'])->middleware('permission:staff.manage');
    Route::put('update/{id}', [StaffController::class, 'handleUpdateStaff'])->middleware('permission:staff.manage');
    Route::delete('delete/{id}', [StaffController::class, 'handleDeleteStaff'])->middleware('permission:staff.manage');
});

Route::middleware(['auth:api', 'tenant', 'permission:roles.manage'])->prefix('roles')->group(function () {
    Route::get('list', [RoleController::class, 'handleListRoles']);
    Route::get('{id}/detail', [RoleController::class, 'handleGetRole']);
    Route::post('create', [RoleController::class, 'handleCreateRole']);
    Route::put('update/{id}', [RoleController::class, 'handleUpdateRole']);
    Route::delete('delete/{id}', [RoleController::class, 'handleDeleteRole']);
});

Route::middleware(['auth:api', 'tenant', 'permission:roles.manage'])->prefix('permissions')->group(function () {
    Route::get('list', [PermissionController::class, 'handleListPermissions']);
});
