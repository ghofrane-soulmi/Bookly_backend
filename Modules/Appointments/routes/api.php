<?php

use Illuminate\Support\Facades\Route;
use Modules\Appointments\Http\Controllers\AppointmentController;

Route::middleware(['auth:api', 'tenant'])->prefix('appointments')->group(function () {
    Route::get('list', [AppointmentController::class, 'handleListAppointments']);
    Route::get('{id}/detail', [AppointmentController::class, 'handleGetAppointment']);
    Route::post('create', [AppointmentController::class, 'handleCreateAppointment']);
    Route::put('update/{id}', [AppointmentController::class, 'handleUpdateAppointment']);
    Route::delete('delete/{id}', [AppointmentController::class, 'handleDeleteAppointment']);
});
