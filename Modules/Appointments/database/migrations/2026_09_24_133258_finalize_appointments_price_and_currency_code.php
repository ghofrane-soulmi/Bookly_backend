<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->bigInteger('price')->nullable(false)->change();
            $table->char('currency_code', 3)->nullable(false)->change();
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['currency_code']);
            $table->bigInteger('price')->nullable()->change();
            $table->char('currency_code', 3)->nullable()->change();
        });
    }
};
