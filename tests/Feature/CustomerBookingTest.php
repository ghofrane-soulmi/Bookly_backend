<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Appointments\Mail\AppointmentCancelledMail;
use Modules\Appointments\Mail\AppointmentConfirmationMail;
use Modules\Appointments\Models\Appointment;
use Modules\Auth\Models\User;
use Modules\Booking\Models\Customer;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Models\Scopes\TenantScope;
use Modules\Tenant\Support\Tenant;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class CustomerBookingTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: Business, 1: User, 2: Service}
     */
    protected function bookableBusiness(array $attrs = []): array
    {
        [$business, $owner] = $this->createBusinessWithOwner($attrs);
        $service = $this->asTenant($business, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]));
        app(Tenant::class)->set(null);

        return [$business, $owner, $service];
    }

    protected function customer(array $attrs = []): Customer
    {
        return Customer::create(['name' => 'Carla', 'email' => 'carla@example.test', 'password' => 'secret123', ...$attrs]);
    }

    protected function asCustomer(Customer $customer): static
    {
        auth('customer')->forgetUser();
        app(Tenant::class)->set(null);

        return $this->withHeader('Authorization', 'Bearer '.auth('customer')->login($customer));
    }

    protected function book(Customer $customer, Business $business, array $payload)
    {
        return $this->asCustomer($customer)->postJson("/api/customer/businesses/{$business->slug}/appointments", $payload);
    }

    public function test_customer_can_register_and_login(): void
    {
        $this->postJson('/api/customer/auth/register', [
            'name' => 'Carla', 'email' => 'carla@example.test',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertStatus(201)->assertJsonStructure(['data' => ['token', 'customer' => ['id', 'email']]]);

        $this->postJson('/api/customer/auth/login', ['email' => 'carla@example.test', 'password' => 'wrong-pass'])
            ->assertStatus(401);

        $token = $this->postJson('/api/customer/auth/login', ['email' => 'carla@example.test', 'password' => 'secret123'])
            ->assertOk()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/customer/me')
            ->assertOk()->assertJsonPath('data.email', 'carla@example.test')
            ->assertJsonMissingPath('data.password');
    }

    public function test_customer_and_staff_tokens_are_not_interchangeable(): void
    {
        [, $owner] = $this->bookableBusiness();
        $staffToken = auth('api')->login($owner);
        $customerToken = auth('customer')->login($this->customer());

        // login() leaves its token cached on the shared JWT instance, which
        // would otherwise be used instead of the request header.
        $reset = function () {
            auth('api')->forgetUser();
            auth('customer')->forgetUser();
            app('tymon.jwt')->unsetToken();
        };

        $reset();
        $this->withHeader('Authorization', "Bearer {$staffToken}")->getJson('/api/customer/me')->assertStatus(401);

        $reset();
        $this->withHeader('Authorization', "Bearer {$customerToken}")->getJson('/api/clients/list')->assertStatus(401);
    }

    public function test_public_business_page_exposes_only_safe_fields(): void
    {
        [$business, $owner] = $this->bookableBusiness();

        $this->getJson("/api/public/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.business.slug', $business->slug)
            ->assertJsonPath('data.services.0.name', 'Cut')
            ->assertJsonPath('data.staff.0.id', $owner->id)
            ->assertJsonMissingPath('data.staff.0.email')
            ->assertJsonMissingPath('data.business.id');

        $this->getJson('/api/public/businesses/does-not-exist')->assertStatus(404);
    }

    public function test_inactive_business_is_not_public(): void
    {
        [$business] = $this->bookableBusiness(['is_active' => false]);

        $this->getJson("/api/public/businesses/{$business->slug}")->assertStatus(404);
    }

    public function test_slots_respect_hours_and_existing_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00', 'UTC')); // a Monday

        [$business, $owner, $service] = $this->bookableBusiness([
            'operating_hours' => ['tuesday' => ['open' => '09:00', 'close' => '11:00']],
        ]);

        $this->asTenant($business, function () use ($owner, $service) {
            $client = Client::create(['name' => 'Walk-in']);
            Appointment::create([
                'client_id' => $client->id, 'service_id' => $service->id, 'user_id' => $owner->id,
                'starts_at' => '2026-11-17 09:30:00', 'ends_at' => '2026-11-17 10:00:00', 'price' => $service->price,
            ]);
        });
        app(Tenant::class)->set(null);

        $slots = $this->getJson("/api/public/businesses/{$business->slug}/slots?service_id={$service->id}&date=2026-11-17")
            ->assertOk()->json('data.slots');

        $starts = array_map(fn ($s) => Carbon::parse($s['starts_at'])->format('H:i'), $slots);

        // 09:00-11:00 in 15-min steps for a 30-min service, minus the ones
        // overlapping the existing 09:30-10:00 booking.
        $this->assertSame(['09:00', '10:00', '10:15', '10:30'], $starts);

        // Monday has no hours configured, so it's closed.
        $this->getJson("/api/public/businesses/{$business->slug}/slots?service_id={$service->id}&date=2026-11-16")
            ->assertOk()->assertJsonCount(0, 'data.slots');
    }

    public function test_customer_can_book_and_is_linked_to_a_client(): void
    {
        Mail::fake();
        [$business, $owner, $service] = $this->bookableBusiness();
        $customer = $this->customer();

        $this->book($customer, $business, [
            'service_id' => $service->id, 'starts_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ])->assertStatus(201)
            ->assertJsonPath('data.staff.id', $owner->id)
            ->assertJsonPath('data.status', Appointment::STATUS_SCHEDULED)
            ->assertJsonPath('data.can_cancel', true);

        $client = Client::withoutGlobalScope(TenantScope::class)->where('customer_id', $customer->id)->first();
        $this->assertNotNull($client);
        $this->assertSame($business->id, $client->business_id);
        $this->assertSame('carla@example.test', $client->email);

        Mail::assertSent(AppointmentConfirmationMail::class);
    }

    public function test_mail_outage_does_not_fail_a_committed_booking(): void
    {
        // Real SMTP transport pointed at a closed port: sending will throw.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        [$business, , $service] = $this->bookableBusiness();

        $this->book($this->customer(), $business, [
            'service_id' => $service->id, 'starts_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ])->assertStatus(201);

        $this->assertSame(1, Appointment::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_booking_reuses_existing_client_with_same_email(): void
    {
        Mail::fake();
        [$business, , $service] = $this->bookableBusiness();
        $existing = $this->asTenant($business, fn () => Client::create(['name' => 'Carla', 'email' => 'carla@example.test']));
        $customer = $this->customer();

        $this->book($customer, $business, [
            'service_id' => $service->id, 'starts_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ])->assertStatus(201);

        $this->assertSame(1, Client::withoutGlobalScope(TenantScope::class)->where('business_id', $business->id)->count());
        $this->assertSame($customer->id, Client::withoutGlobalScope(TenantScope::class)->find($existing->id)->customer_id);
    }

    public function test_double_booking_the_only_staff_member_is_rejected(): void
    {
        Mail::fake();
        [$business, , $service] = $this->bookableBusiness();
        $at = now()->addDay()->setTime(10, 0)->toDateTimeString();

        $this->book($this->customer(), $business, ['service_id' => $service->id, 'starts_at' => $at])->assertStatus(201);
        $this->book($this->customer(['email' => 'dan@example.test']), $business, ['service_id' => $service->id, 'starts_at' => $at])
            ->assertStatus(409);
    }

    public function test_booking_in_the_past_or_outside_hours_is_rejected(): void
    {
        [$business, , $service] = $this->bookableBusiness([
            'operating_hours' => ['monday' => ['open' => '09:00', 'close' => '17:00']],
        ]);
        $customer = $this->customer();

        $this->book($customer, $business, ['service_id' => $service->id, 'starts_at' => now()->subDay()->toDateTimeString()])
            ->assertStatus(422);
        $this->book($customer, $business, ['service_id' => $service->id, 'starts_at' => now()->next(Carbon::MONDAY)->setTime(20, 0)->toDateTimeString()])
            ->assertStatus(422);
    }

    public function test_cannot_book_another_businesss_service(): void
    {
        [$business] = $this->bookableBusiness();
        [, , $otherService] = $this->bookableBusiness();

        $this->book($this->customer(), $business, [
            'service_id' => $otherService->id, 'starts_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422);
    }

    public function test_history_lists_only_own_appointments_across_businesses(): void
    {
        Mail::fake();
        [$a, , $serviceA] = $this->bookableBusiness();
        [$b, , $serviceB] = $this->bookableBusiness();
        $carla = $this->customer();
        $dan = $this->customer(['email' => 'dan@example.test']);

        $this->book($carla, $a, ['service_id' => $serviceA->id, 'starts_at' => now()->addDays(1)->setTime(10, 0)->toDateTimeString()])->assertStatus(201);
        $this->book($carla, $b, ['service_id' => $serviceB->id, 'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString()])->assertStatus(201);
        $this->book($dan, $a, ['service_id' => $serviceA->id, 'starts_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString()])->assertStatus(201);

        $history = $this->asCustomer($carla)->getJson('/api/customer/appointments')->assertOk()->json('data');

        $this->assertCount(2, $history);
        $this->assertEqualsCanonicalizing([$a->slug, $b->slug], array_column(array_column($history, 'business'), 'slug'));
    }

    public function test_customer_can_cancel_own_appointment_but_not_others(): void
    {
        Mail::fake();
        [$business, , $service] = $this->bookableBusiness();
        $carla = $this->customer();
        $dan = $this->customer(['email' => 'dan@example.test']);

        $id = $this->book($carla, $business, [
            'service_id' => $service->id, 'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
        ])->assertStatus(201)->json('data.id');

        $this->asCustomer($dan)->postJson("/api/customer/appointments/{$id}/cancel")->assertStatus(404);
        $this->asCustomer($carla)->postJson("/api/customer/appointments/{$id}/cancel")
            ->assertOk()->assertJsonPath('data.status', Appointment::STATUS_CANCELLED);

        Mail::assertSent(AppointmentCancelledMail::class);
    }

    public function test_cancel_is_blocked_close_to_start_time(): void
    {
        Mail::fake();
        [$business, , $service] = $this->bookableBusiness();
        $carla = $this->customer();

        $id = $this->book($carla, $business, [
            'service_id' => $service->id, 'starts_at' => now()->addMinutes(60)->toDateTimeString(),
        ])->assertStatus(201)->json('data.id');

        $this->asCustomer($carla)->postJson("/api/customer/appointments/{$id}/cancel")->assertStatus(422);
    }

    public function test_profile_update_syncs_business_client_records(): void
    {
        Mail::fake();
        [$business, , $service] = $this->bookableBusiness();
        $carla = $this->customer();

        $this->book($carla, $business, [
            'service_id' => $service->id, 'starts_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ])->assertStatus(201);

        $this->asCustomer($carla)->putJson('/api/customer/me', ['name' => 'Carla B.', 'phone' => '+216 11 222 333'])->assertOk();

        $client = Client::withoutGlobalScope(TenantScope::class)->where('customer_id', $carla->id)->first();
        $this->assertSame('Carla B.', $client->name);
        $this->assertSame('+216 11 222 333', $client->phone);

        $this->asCustomer($carla)->putJson('/api/customer/me', [
            'password' => 'newsecret123', 'password_confirmation' => 'newsecret123', 'current_password' => 'wrong',
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');
    }

    public function test_admin_cannot_promote_to_owner_or_edit_the_owner(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();
        $admin = $this->asTenant($business, function () use ($business) {
            $u = User::factory()->create(['business_id' => $business->id]);
            $u->assignRole(User::ROLE_ADMIN);

            return $u;
        });

        $this->actingAsTenantUser($admin)->putJson("/api/staff/update/{$admin->id}", ['role' => User::ROLE_OWNER])
            ->assertJsonPath('status.code', 422);
        $this->actingAsTenantUser($admin)->putJson("/api/staff/update/{$owner->id}", ['password' => 'takeover123'])
            ->assertJsonPath('status.code', 403);
    }

    public function test_staff_member_can_edit_own_profile(): void
    {
        [$business] = $this->createBusinessWithOwner();
        $staff = $this->asTenant($business, function () use ($business) {
            $u = User::factory()->create(['business_id' => $business->id, 'password' => 'oldpass123']);
            $u->assignRole(User::ROLE_STAFF);

            return $u;
        });

        $this->actingAsTenantUser($staff)->putJson('/api/auth/profile', ['name' => 'New Name'])
            ->assertJsonPath('status.code', 200);
        $this->assertSame('New Name', $staff->fresh()->name);

        $this->actingAsTenantUser($staff)->putJson('/api/auth/profile', ['password' => 'newpass123', 'current_password' => 'nope'])
            ->assertJsonPath('status.code', 422);
    }

    public function test_naive_times_are_read_in_the_business_timezone(): void
    {
        Mail::fake();
        [$business, $owner] = $this->createBusinessWithOwner(['timezone' => 'Africa/Tunis']);
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);
        $day = now()->addDays(2)->format('Y-m-d');

        $this->actingAsTenantUser($owner)->postJson('/api/appointments/create', [
            'client_id' => $client->id, 'service_id' => $service->id, 'user_id' => $owner->id,
            'starts_at' => "{$day} 14:00",
        ])->assertJsonPath('status.code', 201);

        // Tunis is UTC+1 all year, so 14:00 local is stored as 13:00 UTC.
        $this->assertDatabaseHas('appointments', ['starts_at' => "{$day} 13:00:00"]);
    }
}
