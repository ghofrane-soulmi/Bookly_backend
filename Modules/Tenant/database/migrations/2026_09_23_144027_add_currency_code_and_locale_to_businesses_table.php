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
        Schema::table('businesses', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable()->default('USD')->after('currency');
            $table->string('locale', 10)->default('en')->after('currency_code');
        });

        DB::statement('UPDATE businesses SET currency_code = currency');

        Schema::table('businesses', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable(false)->default('USD')->change();
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->dropColumn('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('currency_code');
        });

        DB::statement('UPDATE businesses SET currency = currency_code');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['currency_code']);
            $table->dropColumn(['currency_code', 'locale']);
        });
    }
};
