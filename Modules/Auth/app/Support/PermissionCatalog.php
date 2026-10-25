<?php

namespace Modules\Auth\Support;

/**
 * The single source of truth for the app's fixed permission catalog — the
 * seeding migration and DefaultRoles both read from this list instead of
 * duplicating it, so they can't silently drift apart.
 */
class PermissionCatalog
{
    public const ALL = [
        'services.view',
        'services.manage',
        'clients.view',
        'clients.manage',
        'appointments.view',
        'appointments.manage',
        'staff.view',
        'staff.manage',
        'business.manage',
        'dashboard.view',
        'roles.manage',
    ];
}
