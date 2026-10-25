<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Support\DefaultRoles;
use Modules\Tenant\Models\Business;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The previous migration only dropped the old role_id column — it never
     * created spatie roles for businesses that already existed, so without
     * this step every pre-existing user would have zero roles/permissions
     * and get 403'd everywhere. Seeds the 3 built-in roles per business and
     * re-assigns each user from the role name captured in
     * _role_id_rollback_backup.
     */
    public function up(): void
    {
        Business::query()->orderBy('id')->chunkById(100, function ($businesses) {
            foreach ($businesses as $business) {
                DefaultRoles::seed($business);
            }
        });

        $registrar = app(PermissionRegistrar::class);

        foreach (DB::table('_role_id_rollback_backup')->orderBy('user_id')->get() as $backup) {
            $user = DB::table('users')->where('id', $backup->user_id)->first(['id', 'business_id']);

            if (! $user) {
                continue;
            }

            $registrar->setPermissionsTeamId($user->business_id);

            $roleId = DB::table('roles')
                ->where('business_id', $user->business_id)
                ->where('name', $backup->role_name)
                ->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => \Modules\Auth\Models\User::class,
                'model_id' => $user->id,
                'business_id' => $user->business_id,
            ]);
        }

        Schema::dropIfExists('_role_id_rollback_backup');
    }

    public function down(): void
    {
        Schema::create('_role_id_rollback_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('role_name');
        });

        DB::statement('
            INSERT INTO _role_id_rollback_backup (user_id, role_name)
            SELECT model_has_roles.model_id, roles.name
            FROM model_has_roles
            INNER JOIN roles ON roles.id = model_has_roles.role_id
            WHERE model_has_roles.model_type = ?
        ', [\Modules\Auth\Models\User::class]);

        DB::table('model_has_roles')->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
    }
};
