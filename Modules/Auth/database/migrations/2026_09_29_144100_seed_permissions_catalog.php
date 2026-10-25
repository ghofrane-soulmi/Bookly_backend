<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Support\PermissionCatalog;

return new class extends Migration
{
    /**
     * The permission catalog is a fixed set defined by what the app's
     * controllers actually check — not something businesses create. Global
     * (not team-scoped): every business's roles draw from this same list.
     * Initial set only — new permissions ship as new migrations, matching
     * how currencies/the old roles table were seeded.
     */
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert(array_map(fn (string $name) => [
            'name' => $name,
            'guard_name' => 'api',
            'created_at' => $now,
            'updated_at' => $now,
        ], PermissionCatalog::ALL));
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', PermissionCatalog::ALL)->delete();
    }
};
