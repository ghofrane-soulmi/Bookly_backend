<?php

use Illuminate\Support\Facades\Route;
use Modules\Platform\Http\Controllers\PlatformController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('platforms', PlatformController::class)->names('platform');
});
