<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Initial seed set only — this matches Platform's currencies migration
        // pattern. Custom per-business roles are a later phase; these three
        // are the only roles the app currently understands.
        DB::table('roles')->insert([
            ['name' => User::ROLE_OWNER, 'created_at' => now(), 'updated_at' => now()],
            ['name' => User::ROLE_ADMIN, 'created_at' => now(), 'updated_at' => now()],
            ['name' => User::ROLE_STAFF, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
