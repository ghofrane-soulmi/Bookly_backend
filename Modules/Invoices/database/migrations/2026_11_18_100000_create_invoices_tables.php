<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money columns are integers in the currency's minor units (same convention
 * as services.price / appointments.price). Totals are denormalised onto the
 * invoice and recomputed server-side on every change, so lists and the
 * dashboard can sum them without re-reading every line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            // Workflow state only; payment state (partial/paid/past due) is derived.
            $table->string('status')->default('draft');
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('currency_code', 3);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'issued_on']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('line_total');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('method')->default('cash');
            $table->date('paid_on');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
