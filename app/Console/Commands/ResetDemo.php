<?php

namespace App\Console\Commands;

use Database\Seeders\DemoInvoicesSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rebuilds the public portfolio demo ("Demo Salon") from scratch: removes the
 * demo business with everything that belongs to it, then runs the demo
 * seeders again. Appointments and invoices are seeded relative to today, so
 * this also keeps the dashboard and calendar from going stale.
 *
 * Runs daily on Vercel through GET /api/cron/reset-demo (see vercel.json).
 * Only touches the demo business and the shared demo customer: other
 * businesses and customers are never affected.
 */
class ResetDemo extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Delete and re-seed the public demo business (Demo Salon)';

    public function handle(): int
    {
        $tables = config('permission.table_names');
        $team = config('permission.column_names.team_foreign_key', 'business_id');

        DB::transaction(function () use ($tables, $team) {
            $businessId = DB::table('businesses')->where('slug', DemoSeeder::SLUG)->value('id');

            if ($businessId) {
                $invoiceIds = DB::table('invoices')->where('business_id', $businessId)->pluck('id');
                DB::table('invoice_payments')->whereIn('invoice_id', $invoiceIds)->delete();
                DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
                DB::table('invoices')->where('business_id', $businessId)->delete();

                DB::table('appointments')->where('business_id', $businessId)->delete();
                DB::table('clients')->where('business_id', $businessId)->delete();
                DB::table('services')->where('business_id', $businessId)->delete();

                $roleIds = DB::table($tables['roles'])->where($team, $businessId)->pluck('id');
                DB::table($tables['model_has_roles'])->where($team, $businessId)->delete();
                DB::table($tables['model_has_permissions'])->where($team, $businessId)->delete();
                DB::table($tables['role_has_permissions'])->whereIn('role_id', $roleIds)->delete();
                DB::table($tables['roles'])->where($team, $businessId)->delete();

                $emails = DB::table('users')->where('business_id', $businessId)->pluck('email');
                DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
                DB::table('users')->where('business_id', $businessId)->delete();

                DB::table('businesses')->where('id', $businessId)->delete();
            }

            // The shared demo customer is recreated by DemoSeeder.
            DB::table('customer_password_reset_tokens')->where('email', 'customer@demo.bookly.test')->delete();
            DB::table('customers')->where('email', 'customer@demo.bookly.test')->delete();

            $this->callSilently('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->callSilently('db:seed', ['--class' => DemoInvoicesSeeder::class, '--force' => true]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Demo salon reset: /b/'.DemoSeeder::SLUG);

        return self::SUCCESS;
    }
}
