<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Appointments\Models\Appointment;
use Modules\Auth\Models\User;
use Modules\Clients\Models\Client;
use Modules\Invoices\Mail\InvoiceMail;
use Modules\Invoices\Models\Invoice;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    /**
     * @return array{0: Business, 1: User, 2: Client}
     */
    protected function setUpBusiness(array $attrs = []): array
    {
        [$business, $owner] = $this->createBusinessWithOwner($attrs);
        $client = $this->asTenant($business, fn () => Client::create(['name' => 'Alice', 'email' => 'alice@example.test']));

        return [$business, $owner, $client];
    }

    protected function payload(Client $client, array $overrides = []): array
    {
        return [
            'client_id' => $client->id,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(14)->toDateString(),
            'discount_percent' => 10,
            'tax_percent' => 20,
            'items' => [
                ['description' => 'Haircut', 'quantity' => 2, 'unit_price' => '25.00'],
                ['description' => 'Beard trim', 'quantity' => 1, 'unit_price' => '15.50'],
            ],
            ...$overrides,
        ];
    }

    protected function createInvoice(User $user, Client $client, array $overrides = []): array
    {
        return $this->actingAsTenantUser($user)
            ->postJson('/api/invoices/create', $this->payload($client, $overrides))
            ->assertJsonPath('status.code', 201)
            ->json('data');
    }

    public function test_totals_are_computed_server_side(): void
    {
        [, $owner, $client] = $this->setUpBusiness();

        $invoice = $this->createInvoice($owner, $client);

        // subtotal 2*25 + 15.50 = 65.50; discount 10% = 6.55; tax 20% of 58.95 = 11.79; total 70.74
        $this->assertSame(1001, $invoice['number']);
        $this->assertSame('draft', $invoice['state']);
        $this->assertSame(6550, $invoice['money']['subtotal']['amount']);
        $this->assertSame(655, $invoice['money']['discount']['amount']);
        $this->assertSame(1179, $invoice['money']['tax']['amount']);
        $this->assertSame(7074, $invoice['money']['total']['amount']);
        $this->assertSame('USD', $invoice['money']['total']['currency']);
        $this->assertCount(2, $invoice['items']);
    }

    public function test_numbers_are_sequential_per_business(): void
    {
        [, $ownerA, $clientA] = $this->setUpBusiness();
        [, $ownerB, $clientB] = $this->setUpBusiness();

        $this->assertSame(1001, $this->createInvoice($ownerA, $clientA)['number']);
        $this->assertSame(1002, $this->createInvoice($ownerA, $clientA)['number']);
        $this->assertSame(1001, $this->createInvoice($ownerB, $clientB)['number']);
    }

    public function test_three_decimal_currency_is_handled(): void
    {
        [, $owner, $client] = $this->setUpBusiness(['currency_code' => 'TND']);

        $invoice = $this->createInvoice($owner, $client, [
            'discount_percent' => 0, 'tax_percent' => 0,
            'items' => [['description' => 'Facial', 'quantity' => 1, 'unit_price' => '12.5']],
        ]);

        $this->assertSame(['amount' => 12500, 'currency' => 'TND'], $invoice['money']['total']);
    }

    public function test_payments_drive_the_state_and_cannot_exceed_the_balance(): void
    {
        [, $owner, $client] = $this->setUpBusiness();
        $id = $this->createInvoice($owner, $client, ['discount_percent' => 0, 'tax_percent' => 0,
            'items' => [['description' => 'Cut', 'quantity' => 1, 'unit_price' => '100']]])['id'];

        $pay = fn ($amount) => $this->actingAsTenantUser($owner)->postJson("/api/invoices/{$id}/payments", [
            'amount' => $amount, 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);

        // A payment on a draft issues it.
        $pay('40')->assertJsonPath('status.code', 201)->assertJsonPath('data.state', 'partial');
        $pay('70')->assertJsonPath('status.code', 422)->assertJsonPath('errors.amount.0', 'The payment is larger than the outstanding balance.');
        $pay('60')->assertJsonPath('data.state', 'paid')->assertJsonPath('data.money.balance.amount', 0);
    }

    public function test_unpaid_invoice_past_its_due_date_is_past_due_and_filterable(): void
    {
        [, $owner, $client] = $this->setUpBusiness();
        $overdue = $this->createInvoice($owner, $client, ['issued_on' => now()->subDays(30)->toDateString(), 'due_on' => now()->subDays(5)->toDateString()]);
        $this->createInvoice($owner, $client);

        $this->actingAsTenantUser($owner)->postJson("/api/invoices/{$overdue['id']}/send")->assertJsonPath('data.state', 'past_due');

        $this->actingAsTenantUser($owner)->getJson('/api/invoices/list?state=past_due')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $overdue['id'])
            ->assertJsonPath('meta.summary.counts.past_due', 1)
            ->assertJsonPath('meta.summary.counts.draft', 1);
    }

    public function test_send_marks_sent_and_emails_the_client(): void
    {
        Mail::fake();
        [, $owner, $client] = $this->setUpBusiness();
        $id = $this->createInvoice($owner, $client)['id'];

        $this->actingAsTenantUser($owner)->postJson("/api/invoices/{$id}/send")
            ->assertJsonPath('data.state', 'sent')
            ->assertJsonPath('status.message', 'Invoice sent to alice@example.test');

        Mail::assertSent(InvoiceMail::class, fn ($mail) => $mail->hasTo('alice@example.test'));
    }

    public function test_invoice_email_renders(): void
    {
        [$business, $owner, $client] = $this->setUpBusiness();
        $id = $this->createInvoice($owner, $client)['id'];

        $html = $this->asTenant($business, fn () => (new InvoiceMail(Invoice::with(['client', 'items'])->find($id), $business))->render());

        $this->assertStringContainsString('invoice <strong>#1001</strong>', $html);
        $this->assertStringContainsString('$70.74', $html);
    }

    public function test_completed_appointments_can_be_invoiced_once(): void
    {
        [$business, $owner, $client] = $this->setUpBusiness();
        [$done, $scheduled] = $this->asTenant($business, function () use ($client, $owner) {
            $service = Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 2000]);
            $make = fn ($status, $days) => Appointment::create([
                'client_id' => $client->id, 'service_id' => $service->id, 'user_id' => $owner->id,
                'starts_at' => now()->subDays($days), 'ends_at' => now()->subDays($days)->addMinutes(30),
                'status' => $status, 'price' => $service->price,
            ]);

            return [$make(Appointment::STATUS_COMPLETED, 2), $make(Appointment::STATUS_SCHEDULED, 1)];
        });

        $this->actingAsTenantUser($owner)->getJson('/api/invoices/uninvoiced-appointments')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $done->id);

        $this->actingAsTenantUser($owner)->postJson('/api/invoices/from-appointments', ['appointment_ids' => [$scheduled->id]])
            ->assertJsonPath('status.code', 422);

        $invoice = $this->actingAsTenantUser($owner)->postJson('/api/invoices/from-appointments', ['appointment_ids' => [$done->id]])
            ->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.money.total.amount', 2000)
            ->assertJsonPath('data.items.0.appointment_id', $done->id)
            ->json('data');

        // Already invoiced -> no longer offered, can't be invoiced twice.
        $this->actingAsTenantUser($owner)->getJson('/api/invoices/uninvoiced-appointments')->assertJsonCount(0, 'data');
        $this->actingAsTenantUser($owner)->postJson('/api/invoices/from-appointments', ['appointment_ids' => [$done->id]])
            ->assertJsonPath('status.code', 422);

        // Deleting the invoice frees the appointment again.
        $this->actingAsTenantUser($owner)->deleteJson("/api/invoices/delete/{$invoice['id']}")->assertJsonPath('status.code', 200);
        $this->actingAsTenantUser($owner)->getJson('/api/invoices/uninvoiced-appointments')->assertJsonCount(1, 'data');
    }

    public function test_paid_amounts_protect_edits_and_deletes(): void
    {
        [, $owner, $client] = $this->setUpBusiness();
        $id = $this->createInvoice($owner, $client, ['discount_percent' => 0, 'tax_percent' => 0,
            'items' => [['description' => 'Cut', 'quantity' => 1, 'unit_price' => '100']]])['id'];

        $this->actingAsTenantUser($owner)->postJson("/api/invoices/{$id}/payments", ['amount' => '80', 'method' => 'card', 'paid_on' => now()->toDateString()]);

        $this->actingAsTenantUser($owner)->putJson("/api/invoices/update/{$id}", [
            'items' => [['description' => 'Cut', 'quantity' => 1, 'unit_price' => '50']],
        ])->assertJsonPath('status.code', 422);

        // Rolled back: total unchanged.
        $this->actingAsTenantUser($owner)->getJson("/api/invoices/{$id}/detail")->assertJsonPath('data.money.total.amount', 10000);

        $this->actingAsTenantUser($owner)->deleteJson("/api/invoices/delete/{$id}")->assertJsonPath('status.code', 422);
    }

    public function test_staff_role_cannot_access_invoices(): void
    {
        [$business] = $this->setUpBusiness();
        $staff = $this->asTenant($business, function () use ($business) {
            $u = User::factory()->create(['business_id' => $business->id]);
            $u->assignRole(User::ROLE_STAFF);

            return $u;
        });

        $this->actingAsTenantUser($staff)->getJson('/api/invoices/list')->assertForbidden();
    }

    public function test_admin_role_can_manage_invoices(): void
    {
        [$business, , $client] = $this->setUpBusiness();
        $admin = $this->asTenant($business, function () use ($business) {
            $u = User::factory()->create(['business_id' => $business->id]);
            $u->assignRole(User::ROLE_ADMIN);

            return $u;
        });

        $this->createInvoice($admin, $client);
    }

    public function test_invoices_are_isolated_between_businesses(): void
    {
        [, $ownerA, $clientA] = $this->setUpBusiness();
        [, $ownerB, $clientB] = $this->setUpBusiness();
        $invoiceA = $this->createInvoice($ownerA, $clientA);

        $this->actingAsTenantUser($ownerB)->getJson("/api/invoices/{$invoiceA['id']}/detail")->assertJsonPath('status.code', 404);
        $this->actingAsTenantUser($ownerB)->getJson('/api/invoices/list')->assertJsonPath('meta.total', 0);

        // Can't bill another business's client.
        $this->actingAsTenantUser($ownerB)->postJson('/api/invoices/create', $this->payload($clientA))
            ->assertJsonPath('status.code', 422)
            ->assertJsonValidationErrors(['client_id'], 'errors');
    }

    public function test_duplicate_creates_a_fresh_draft(): void
    {
        [, $owner, $client] = $this->setUpBusiness();
        $source = $this->createInvoice($owner, $client);
        $this->actingAsTenantUser($owner)->postJson("/api/invoices/{$source['id']}/send");

        $this->actingAsTenantUser($owner)->postJson("/api/invoices/{$source['id']}/duplicate")
            ->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.number', 1002)
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.money.total.amount', 7074);
    }
}
