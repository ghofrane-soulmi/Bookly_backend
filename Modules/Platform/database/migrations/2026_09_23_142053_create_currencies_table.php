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
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        // Initial seed set only — future currencies are added via new migrations,
        // not by editing this one, so this list stays a historical record.
        DB::table('currencies')->insert([
            ['code' => 'TND', 'name' => 'Tunisian Dinar', 'symbol' => 'DT', 'decimals' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'MAD', 'name' => 'Moroccan Dirham', 'symbol' => 'DH', 'decimals' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DZD', 'name' => 'Algerian Dinar', 'symbol' => 'DA', 'decimals' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
