<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use Illuminate\Routing\Controller;
use Modules\Tenant\Support\Tenant;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    use ReturnsServerErrorResponse;

    /**
     * Read-only: the permission catalog is fixed by what the app's
     * controllers actually check (see PermissionCatalog), not something
     * businesses create — this just shows which of the current business's
     * roles carry each one.
     *
     * role_has_permissions has no business_id column of its own (only
     * model_has_roles/model_has_permissions are team-scoped), so the
     * eager-loaded 'roles' relation must be constrained explicitly or it
     * would include every business's roles.
     */
    public function handleListPermissions()
    {
        try {
            $businessId = app(Tenant::class)->id();

            $permissions = Permission::with(['roles' => fn ($q) => $q->where('business_id', $businessId)])
                ->orderBy('name')
                ->get();

            $data = $permissions->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'roles' => $permission->roles->pluck('name'),
            ]);

            return response()->json([
                'data' => $data,
                'status' => ['message' => 'Permissions retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }
}
