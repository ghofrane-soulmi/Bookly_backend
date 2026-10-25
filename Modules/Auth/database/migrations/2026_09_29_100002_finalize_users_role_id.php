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
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->change();
            $table->string('role')->default('staff')->after('business_id');
        });

        $roleNamesById = DB::table('roles')->pluck('name', 'id');

        foreach (DB::table('users')->get(['id', 'role_id']) as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'role' => $roleNamesById[$user->role_id] ?? 'staff',
            ]);
        }
    }
};
