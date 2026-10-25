<?php

namespace Modules\Auth\Support;

use Modules\Auth\Models\User;
use Modules\Tenant\Models\Business;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the three built-in roles (owner/admin/staff) for a newly-created
 * business, each scoped to that business via spatie's teams feature
 * (team_foreign_key = business_id — see config/permission.php). Every
 * business gets its own copies of these rows: editing "admin" for one
 * business never touches another's, and owners can add fully custom roles
 * alongside these later without any special-casing.
 */
class DefaultRoles
{
    private const PERMISSIONS = [
        User::ROLE_OWNER => [
            'services.view', 'services.manage',
            'clients.view', 'clients.manage',
            'appointments.view', 'appointments.manage',
            'staff.view', 'staff.manage',
            'business.manage',
            'dashboard.view',
            'roles.manage',
        ],
        User::ROLE_ADMIN => [
            'services.view', 'services.manage',
            'clients.view', 'clients.manage',
            'appointments.view', 'appointments.manage',
            'staff.view', 'staff.manage',
            'dashboard.view',
        ],
        User::ROLE_STAFF => [
            'services.view',
            'clients.view', 'clients.manage',
            'appointments.view', 'appointments.manage',
            'dashboard.view',
        ],
    ];

    public static function seed(Business $business): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

        foreach (self::PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::create(['name' => $roleName, 'guard_name' => 'api', 'business_id' => $business->id]);
            $role->syncPermissions(Permission::whereIn('name', $permissionNames)->get());
        }
    }
}
