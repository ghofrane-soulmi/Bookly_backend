<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->bigInteger('price')->nullable()->after('service_id');
            $table->char('currency_code', 3)->nullable()->after('price');
        });

        // Best-effort backfill for existing appointments: snapshot each one's
        // linked service's *current* price/currency, since no historical price
        // was ever recorded before this column existed. Portable per-row update
        // (no multi-table UPDATE JOIN — that broke on SQLite in the services
        // migration for the exact same reason).
        $services = DB::table('services')->get(['id', 'price', 'currency_code'])->keyBy('id');

        foreach (DB::table('appointments')->get(['id', 'service_id']) as $appointment) {
            $service = $services[$appointment->service_id] ?? null;

            if (! $service) {
                continue;
            }

            DB::table('appointments')->where('id', $appointment->id)->update([
                'price' => $service->price,
                'currency_code' => $service->currency_code,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['price', 'currency_code']);
        });
    }
};
