<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\PermissionCatalog;
use Modules\Tenant\Support\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use ReturnsServerErrorResponse;

    /**
     * The three roles every business is seeded with (see DefaultRoles) —
     * their names are load-bearing elsewhere (StaffController's owner-role
     * guard, "can't delete last owner" style checks), so they can't be
     * renamed or deleted, only have their permissions adjusted.
     */
    protected const BUILT_IN_ROLES = [User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF];

    public function handleListRoles(Request $request)
    {
        try {
            $businessId = app(Tenant::class)->id();

            $roles = Role::where('business_id', $businessId)
                ->withCount('users')
                ->with('permissions')
                ->orderBy('name')
                ->get();

            $data = $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_built_in' => in_array($role->name, self::BUILT_IN_ROLES, true),
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users_count,
                'users' => $role->users()->limit(4)->get(['users.id', 'users.name']),
            ]);

            return response()->json([
                'data' => $data,
                'status' => ['message' => 'Roles retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleGetRole($id)
    {
        try {
            $businessId = app(Tenant::class)->id();

            $role = Role::where('business_id', $businessId)->with('permissions')->find($id);

            if (! $role) {
                return response()->json([
                    'status' => ['message' => 'Role not found', 'code' => 404],
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'is_built_in' => in_array($role->name, self::BUILT_IN_ROLES, true),
                    'permissions' => $role->permissions->pluck('name'),
                ],
                'status' => ['message' => 'Role retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleCreateRole(Request $request)
    {
        try {
            $businessId = app(Tenant::class)->id();

            $validator = Validator::make($request->all(), [
                'name' => [
                    'required', 'string', 'max:255',
                    Rule::unique('roles', 'name')->where('business_id', $businessId),
                ],
                'permissions' => ['array'],
                'permissions.*' => [Rule::in(PermissionCatalog::ALL)],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $data = $validator->validated();

            $role = Role::create(['name' => $data['name'], 'guard_name' => 'api', 'business_id' => $businessId]);
            $role->syncPermissions(Permission::whereIn('name', $data['permissions'] ?? [])->get());

            return response()->json([
                'data' => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')],
                'status' => ['message' => 'Role created successfully', 'code' => 201],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleUpdateRole(Request $request, $id)
    {
        try {
            $businessId = app(Tenant::class)->id();

            $role = Role::where('business_id', $businessId)->find($id);

            if (! $role) {
                return response()->json([
                    'status' => ['message' => 'Role not found', 'code' => 404],
                ]);
            }

            $isBuiltIn = in_array($role->name, self::BUILT_IN_ROLES, true);

            $validator = Validator::make($request->all(), [
                'name' => [
                    $isBuiltIn ? 'prohibited' : 'sometimes',
                    'required', 'string', 'max:255',
                    Rule::unique('roles', 'name')->where('business_id', $businessId)->ignore($role->id),
                ],
                'permissions' => ['array'],
                'permissions.*' => [Rule::in(PermissionCatalog::ALL)],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $data = $validator->validated();

            if (! empty($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions(Permission::whereIn('name', $data['permissions'])->get());
            }

            return response()->json([
                'data' => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->fresh('permissions')->permissions->pluck('name')],
                'status' => ['message' => 'Role updated successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleDeleteRole($id)
    {
        try {
            $businessId = app(Tenant::class)->id();

            $role = Role::where('business_id', $businessId)->withCount('users')->find($id);

            if (! $role) {
                return response()->json([
                    'status' => ['message' => 'Role not found', 'code' => 404],
                ]);
            }

            if (in_array($role->name, self::BUILT_IN_ROLES, true)) {
                return response()->json([
                    'status' => ['message' => 'Built-in roles cannot be deleted.', 'code' => 422],
                ]);
            }

            if ($role->users_count > 0) {
                return response()->json([
                    'status' => ['message' => 'Reassign staff off this role before deleting it.', 'code' => 422],
                ]);
            }

            $role->delete();

            return response()->json([
                'status' => ['message' => 'Role deleted successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }
}
