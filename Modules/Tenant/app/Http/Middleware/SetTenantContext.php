<?php

namespace Modules\Tenant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenant\Support\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            app(Tenant::class)->set($user->business_id);

            // spatie/laravel-permission's teams feature scopes every role/
            // permission lookup (hasRole, can, etc.) to this "team" id —
            // without it set, role checks would silently see no roles at all.
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->business_id);
        }

        return $next($request);
    }
}
