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
            $table->dropColumn('price');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('price_minor', 'price');
        });

        Schema::table('services', function (Blueprint $table) {
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
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['currency_code']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('price', 'price_minor');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->decimal('price', 8, 2)->nullable()->after('duration_minutes');
        });

        // Reverse the minor-units conversion back to decimal, per service's own
        // business currency, mirroring the forward conversion exactly. Portable
        // per-row update for the same reason as the forward migration.
        $decimalsByBusiness = DB::table('businesses')
            ->join('currencies', 'currencies.code', '=', 'businesses.currency_code')
            ->pluck('currencies.decimals', 'businesses.id');

        foreach (DB::table('services')->get(['id', 'business_id', 'price_minor']) as $service) {
            $decimals = $decimalsByBusiness[$service->business_id];

            DB::table('services')->where('id', $service->id)->update([
                'price' => round($service->price_minor / (10 ** $decimals), $decimals),
            ]);
        }

        Schema::table('services', function (Blueprint $table) {
            $table->decimal('price', 8, 2)->nullable(false)->change();
            $table->char('currency_code', 3)->nullable()->change();
        });
    }
};
