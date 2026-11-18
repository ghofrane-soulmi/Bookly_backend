<?php

use Illuminate\Support\Facades\Route;
use Modules\Invoices\Http\Controllers\InvoiceController;

Route::middleware(['auth:api', 'tenant'])->prefix('invoices')->group(function () {
    Route::middleware('permission:invoices.view')->group(function () {
        Route::get('list', [InvoiceController::class, 'handleListInvoices']);
        Route::get('{id}/detail', [InvoiceController::class, 'handleGetInvoice'])->whereNumber('id');
        Route::get('uninvoiced-appointments', [InvoiceController::class, 'handleUninvoicedAppointments']);
    });

    Route::middleware('permission:invoices.manage')->group(function () {
        Route::post('create', [InvoiceController::class, 'handleCreateInvoice']);
        Route::post('from-appointments', [InvoiceController::class, 'handleCreateFromAppointments']);
        Route::put('update/{id}', [InvoiceController::class, 'handleUpdateInvoice'])->whereNumber('id');
        Route::delete('delete/{id}', [InvoiceController::class, 'handleDeleteInvoice'])->whereNumber('id');
        Route::post('{id}/duplicate', [InvoiceController::class, 'handleDuplicateInvoice'])->whereNumber('id');
        Route::post('{id}/send', [InvoiceController::class, 'handleSendInvoice'])->whereNumber('id');
        Route::post('{id}/payments', [InvoiceController::class, 'handleAddPayment'])->whereNumber('id');
        Route::delete('{id}/payments/{paymentId}', [InvoiceController::class, 'handleDeletePayment'])->whereNumber(['id', 'paymentId']);
    });
});
