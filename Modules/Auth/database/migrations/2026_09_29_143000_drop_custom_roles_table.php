<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The minimal roles table (Phase 1's first step) is superseded by
     * spatie/laravel-permission's own 'roles' table, which the very next
     * migration creates — the name collides, so this one has to go first.
     *
     * Dropping users.role_id destroys its data, so each user's role name is
     * backed up into a side table first and restored from it in down() —
     * otherwise a rollback would silently leave every user with no role.
     */
    public function up(): void
    {
        Schema::create('_role_id_rollback_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('role_name');
        });

        DB::statement('
            INSERT INTO _role_id_rollback_backup (user_id, role_name)
            SELECT users.id, roles.name FROM users
            INNER JOIN roles ON roles.id = users.role_id
        ');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }

    public function down(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        DB::table('roles')->insert([
            ['name' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'staff', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
        });

        $roleIdsByName = DB::table('roles')->pluck('id', 'name');

        foreach (DB::table('_role_id_rollback_backup')->get() as $backup) {
            DB::table('users')->where('id', $backup->user_id)->update([
                'role_id' => $roleIdsByName[$backup->role_name] ?? null,
            ]);
        }

        Schema::dropIfExists('_role_id_rollback_backup');
    }
};
