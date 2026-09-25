<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['business_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Adding the composite index above let InnoDB silently drop the
            // implicit single-column index it had auto-created to support the
            // business_id FK (the composite's leftmost column also satisfies
            // it), making this composite index the FK's only remaining
            // support. MySQL refuses to drop it while the FK depends on it,
            // so the FK has to be dropped and re-added around it — re-adding
            // it makes InnoDB recreate its own implicit supporting index.
            $table->dropForeign(['business_id']);
            $table->dropIndex(['business_id', 'starts_at']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }
};
