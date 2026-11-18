<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Databases migrated before the Invoices module exist already have their
 * permission catalog and per-business roles, so the new permissions are
 * added here and granted to every business's owner and admin roles.
 * Idempotent: on a fresh install the catalog migration already inserted them.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['invoices.view', 'invoices.manage'];

    private const ROLES = ['owner', 'admin'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore(array_map(fn (string $name) => [
            'name' => $name,
            'guard_name' => 'api',
            'created_at' => $now,
            'updated_at' => $now,
        ], self::PERMISSIONS));

        $permissionIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'api')->pluck('id');
        $roleIds = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');

        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId];
            }
        }

        if ($rows) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
