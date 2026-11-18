<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Appointments\Models\Appointment;
use Modules\Clients\Models\Client;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Support\InvoiceService;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

/**
 * A few invoices in every state for the demo salon. Runs from DemoSeeder,
 * and on its own for databases seeded before invoices existed:
 *
 *   php artisan db:seed --class=DemoInvoicesSeeder
 *
 * Does nothing if the demo business is missing or already has invoices.
 */
class DemoInvoicesSeeder extends Seeder
{
    public function run(InvoiceService $invoices): void
    {
        $business = Business::where('slug', DemoSeeder::SLUG)->first();

        if (! $business) {
            return;
        }

        app(Tenant::class)->set($business->id);

        if (Invoice::withTrashed()->exists()) {
            $this->command?->info('Demo invoices already exist — skipping.');

            return;
        }

        $customer = Client::whereNotNull('customer_id')->first();
        $walkIn = Client::whereNull('customer_id')->first();
        $date = fn (int $days) => today()->addDays($days)->toDateString();
        $line = fn (string $description, $price, $qty = 1) => ['description' => $description, 'quantity' => $qty, 'unit_price' => (string) $price];

        // 1) From the completed appointment, paid in full by card.
        $completed = Appointment::where('status', Appointment::STATUS_COMPLETED)->with(['service', 'staff'])->get();
        if ($completed->isNotEmpty()) {
            $paid = $invoices->fromAppointments($business, $completed->where('client_id', $completed->first()->client_id));
            $invoices->addPayment($paid, $paid->total, 'card', $date(0), null);
        }

        if ($walkIn) {
            // 2) Issued three weeks ago, unpaid, past due.
            $overdue = $invoices->create($business, [
                'client_id' => $walkIn->id, 'issued_on' => $date(-21), 'due_on' => $date(-7),
                'discount_percent' => 0, 'tax_percent' => 19, 'notes' => 'Thank you for your visit!',
            ], [$line('Beard trim', 15), $line('Beard oil (30 ml)', 22.5)]);
            $invoices->markSent($overdue);
        }

        if ($customer) {
            // 3) Package, half paid in cash, due next week.
            $partial = $invoices->create($business, [
                'client_id' => $customer->id, 'issued_on' => $date(-3), 'due_on' => $date(11),
                'discount_percent' => 10, 'tax_percent' => 19,
            ], [$line('Relaxing facial — 3-session package', 70, 3)]);
            $invoices->addPayment($partial, intdiv($partial->total, 2), 'cash', $date(-3), 'Deposit');

            // 4) A draft still being prepared.
            $invoices->create($business, [
                'client_id' => $customer->id, 'issued_on' => $date(0), 'due_on' => $date(14),
                'discount_percent' => 0, 'tax_percent' => 19,
            ], [$line('Hair colouring', 90), $line('Haircut & styling', 35)]);
        }

        $this->command?->info('Demo invoices created.');
    }
}
