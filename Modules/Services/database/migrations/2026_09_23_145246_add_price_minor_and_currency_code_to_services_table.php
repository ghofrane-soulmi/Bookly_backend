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
        Schema::table('services', function (Blueprint $table) {
            $table->bigInteger('price_minor')->nullable()->after('price');
            $table->char('currency_code', 3)->nullable()->after('price_minor');
        });

        // Convert each service's decimal price to integer minor units using its own
        // business's currency decimals (e.g. cents for USD, millimes for TND). Done
        // as a portable per-row update rather than a MySQL-only multi-table UPDATE
        // JOIN, since this also needs to run against the SQLite test database.
        $decimalsByBusiness = DB::table('businesses')
            ->join('currencies', 'currencies.code', '=', 'businesses.currency_code')
            ->pluck('currencies.decimals', 'businesses.id');

        $currencyCodeByBusiness = DB::table('businesses')->pluck('currency_code', 'id');

        foreach (DB::table('services')->get(['id', 'business_id', 'price']) as $service) {
            $decimals = $decimalsByBusiness[$service->business_id];
            $currencyCode = $currencyCodeByBusiness[$service->business_id];

            DB::table('services')->where('id', $service->id)->update([
                'price_minor' => (int) round(((float) $service->price) * (10 ** $decimals)),
                'currency_code' => $currencyCode,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['price_minor', 'currency_code']);
        });
    }
};
