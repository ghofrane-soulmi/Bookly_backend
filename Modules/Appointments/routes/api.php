<?php

use Illuminate\Support\Facades\Route;
use Modules\Appointments\Http\Controllers\AppointmentController;

Route::middleware(['auth:api', 'tenant'])->prefix('appointments')->group(function () {
    Route::get('list', [AppointmentController::class, 'handleListAppointments'])->middleware('permission:appointments.view');
    Route::get('{id}/detail', [AppointmentController::class, 'handleGetAppointment'])->middleware('permission:appointments.view');
    Route::post('create', [AppointmentController::class, 'handleCreateAppointment'])->middleware('permission:appointments.manage');
    Route::put('update/{id}', [AppointmentController::class, 'handleUpdateAppointment'])->middleware('permission:appointments.manage');
    Route::delete('delete/{id}', [AppointmentController::class, 'handleDeleteAppointment'])->middleware('permission:appointments.manage');
});
