<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')->constrained('roles')->restrictOnDelete();
        });

        $roleIdsByName = DB::table('roles')->pluck('id', 'name');

        foreach (DB::table('users')->get(['id', 'role']) as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'role_id' => $roleIdsByName[$user->role] ?? null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
