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

// Serverless scheduler hook (see CronController). Throttled; secret-protected.
Route::match(['get', 'post'], 'cron/reminders', [\Modules\Appointments\Http\Controllers\CronController::class, 'reminders'])
    ->middleware('throttle:10,1');
Route::match(['get', 'post'], 'cron/reset-demo', [\Modules\Appointments\Http\Controllers\CronController::class, 'resetDemo'])
    ->middleware('throttle:3,1');
