<?php

use Illuminate\Support\Facades\Route;
use Modules\Booking\Http\Controllers\CustomerAuthController;
use Modules\Booking\Http\Controllers\CustomerBookingController;
use Modules\Booking\Http\Controllers\PublicBusinessController;

// Front office (public, customer-facing) API.

Route::prefix('public/businesses/{slug}')->middleware('throttle:120,1')->group(function () {
    Route::get('/', [PublicBusinessController::class, 'show']);
    Route::get('slots', [PublicBusinessController::class, 'slots']);
});

Route::prefix('customer')->group(function () {
    Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
        Route::post('register', [CustomerAuthController::class, 'register']);
        Route::post('login', [CustomerAuthController::class, 'login']);
        Route::post('refresh', [CustomerAuthController::class, 'refresh']);
        Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword']);
        Route::post('reset-password', [CustomerAuthController::class, 'resetPassword']);
    });

    Route::middleware('auth:customer')->group(function () {
        Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
        Route::get('me', [CustomerAuthController::class, 'me']);
        Route::put('me', [CustomerAuthController::class, 'updateProfile']);

        Route::get('appointments', [CustomerBookingController::class, 'index']);
        Route::post('appointments/{id}/cancel', [CustomerBookingController::class, 'cancel'])->whereNumber('id');
        Route::post('businesses/{slug}/appointments', [CustomerBookingController::class, 'store'])->middleware('throttle:20,1');
    });
});
