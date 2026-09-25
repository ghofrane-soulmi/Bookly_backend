<?php

namespace Modules\Tenant\Support;

/**
 * Thrown by TenantScope when a tenant-scoped model is queried with no tenant
 * context set. This should never happen on a properly-routed request (every
 * business-data route carries the 'tenant' middleware) — if it fires, it
 * means a route is missing that middleware. Platform/console code that
 * legitimately needs to query across all tenants must bypass explicitly via
 * ->withoutGlobalScope(TenantScope::class), not rely on this failing open.
 */
class NoTenantContextException extends \RuntimeException {}
