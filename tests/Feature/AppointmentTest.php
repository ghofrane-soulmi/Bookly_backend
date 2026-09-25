<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Appointments\Mail\AppointmentCancelledMail;
use Modules\Appointments\Mail\AppointmentConfirmationMail;
use Modules\Appointments\Mail\AppointmentRescheduledMail;
use Modules\Appointments\Models\Appointment;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_appointment_can_be_created(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice', 'email' => 'alice@example.test']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $response = $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertJsonPath('status.code', 201);

        // price/currency_code are snapshotted server-side from the service,
        // not client-supplied — see Phase 0 Step 5.
        $this->assertDatabaseHas('appointments', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price->getMinorAmount()->toInt(),
            'status' => Appointment::STATUS_SCHEDULED,
        ]);

        Mail::assertSent(AppointmentConfirmationMail::class);
    }

    public function test_confirmation_email_is_not_sent_when_business_disables_it(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['notify_confirmation_email' => false]);
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice', 'email' => 'alice@example.test']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
        ])->assertJsonPath('status.code', 201);

        Mail::assertNotSent(AppointmentConfirmationMail::class);
    }

    public function test_overlapping_appointment_for_same_staff_is_rejected(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 60, 'price' => 20]),
        ]);

        $startsAt = now()->addDay()->setTime(10, 0);

        $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $response = $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => $startsAt->copy()->addMinutes(30)->toDateTimeString(),
        ]);

        $response->assertJsonPath('status.code', 409);
    }

    public function test_non_overlapping_appointment_for_same_staff_is_allowed(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $startsAt = now()->addDay()->setTime(10, 0);

        $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $response = $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => $startsAt->copy()->addMinutes(30)->toDateTimeString(),
        ]);

        $response->assertJsonPath('status.code', 201);
    }

    public function test_cancelled_appointment_does_not_block_conflict_check(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 60, 'price' => 20]),
        ]);

        $startsAt = now()->addDay()->setTime(10, 0);

        $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
            'status' => Appointment::STATUS_CANCELLED,
        ]));

        $response = $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => $startsAt->copy()->addMinutes(30)->toDateTimeString(),
        ]);

        $response->assertJsonPath('status.code', 201);
    }

    public function test_create_rejects_cross_tenant_client_service_and_user_ids(): void
    {
        Mail::fake();

        [$businessA] = $this->createBusinessWithOwner();
        [, $userB] = $this->createBusinessWithOwner();

        [$foreignClient, $foreignService] = $this->asTenant($businessA, fn () => [
            Client::create(['name' => 'Not Yours']),
            Service::create(['name' => 'Not Yours Either', 'duration_minutes' => 30, 'price' => 10]),
        ]);

        $response = $this->actingAsTenantUser($userB)->postJson('/api/appointments/create', [
            'client_id' => $foreignClient->id,
            'service_id' => $foreignService->id,
            'user_id' => $userB->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.client_id.0', 'The selected client id is invalid.')
            ->assertJsonPath('errors.service_id.0', 'The selected service id is invalid.');
    }

    public function test_appointment_can_be_updated(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $appointment = $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $response = $this->actingAsTenantUser($user)
            ->putJson("/api/appointments/update/{$appointment->id}", [
                'status' => Appointment::STATUS_COMPLETED,
                'notes' => 'All done',
            ]);

        $response->assertJsonPath('status.code', 200)
            ->assertJsonPath('data.status', Appointment::STATUS_COMPLETED);

        $this->assertSame('All done', $appointment->fresh()->notes);
    }

    public function test_appointment_can_be_deleted(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $appointment = $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $this->actingAsTenantUser($user)
            ->deleteJson("/api/appointments/delete/{$appointment->id}")
            ->assertJsonPath('status.code', 200);

        $this->assertSoftDeleted($appointment);
        $this->assertNull(Appointment::find($appointment->id));
    }

    public function test_appointment_list_returns_created_appointment(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $response = $this->actingAsTenantUser($user)->getJson('/api/appointments/list');

        $response->assertJsonPath('status.code', 200);
        $this->assertCount(1, $response->json('data'));
    }

    protected function restrictedHours(): array
    {
        return [
            'monday' => ['open' => '09:00', 'close' => '18:00'],
            'tuesday' => null,
            'wednesday' => null,
            'thursday' => null,
            'friday' => null,
            'saturday' => null,
            'sunday' => null,
        ];
    }

    public function test_appointment_outside_operating_hours_is_rejected(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['operating_hours' => $this->restrictedHours()]);
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $startsAt = Carbon::now()->next(Carbon::MONDAY)->setTime(20, 0);

        $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => $startsAt->toDateTimeString(),
        ])->assertJsonPath('status.code', 422);
    }

    public function test_appointment_on_closed_day_is_rejected(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['operating_hours' => $this->restrictedHours()]);
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $startsAt = Carbon::now()->next(Carbon::TUESDAY)->setTime(10, 0);

        $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => $startsAt->toDateTimeString(),
        ])->assertJsonPath('status.code', 422);
    }

    public function test_appointment_within_operating_hours_is_allowed(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['operating_hours' => $this->restrictedHours()]);
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $startsAt = Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0);

        $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => $startsAt->toDateTimeString(),
        ])->assertJsonPath('status.code', 201);
    }

    public function test_business_with_no_operating_hours_allows_any_time(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $startsAt = Carbon::now()->next(Carbon::TUESDAY)->setTime(3, 0);

        $this->actingAsTenantUser($user)->postJson('/api/appointments/create', [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'starts_at' => $startsAt->toDateTimeString(),
        ])->assertJsonPath('status.code', 201);
    }

    public function test_updating_fields_other_than_starts_at_is_not_revalidated_against_hours(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['operating_hours' => $this->restrictedHours()]);
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        // Simulate a legacy appointment booked before hours were restricted: it sits
        // on a now-closed day, but only its notes are being edited here, not its time.
        $legacyStartsAt = Carbon::now()->next(Carbon::TUESDAY)->setTime(10, 0);

        $appointment = $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => $legacyStartsAt,
            'ends_at' => $legacyStartsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $this->actingAsTenantUser($user)
            ->putJson("/api/appointments/update/{$appointment->id}", ['notes' => 'Updated notes'])
            ->assertJsonPath('status.code', 200);
    }

    public function test_rescheduling_outside_operating_hours_is_rejected(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['operating_hours' => $this->restrictedHours()]);
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $startsAt = Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0);

        $appointment = $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $this->actingAsTenantUser($user)
            ->putJson("/api/appointments/update/{$appointment->id}", [
                'starts_at' => $startsAt->copy()->setTime(20, 0)->toDateTimeString(),
            ])
            ->assertJsonPath('status.code', 422);
    }

    public function test_cancellation_email_is_sent_when_appointment_is_cancelled(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice', 'email' => 'alice@example.test']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $appointment = $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $this->actingAsTenantUser($user)
            ->putJson("/api/appointments/update/{$appointment->id}", ['status' => Appointment::STATUS_CANCELLED])
            ->assertJsonPath('status.code', 200);

        Mail::assertSent(AppointmentCancelledMail::class);
        Mail::assertNotSent(AppointmentRescheduledMail::class);
    }

    public function test_reschedule_email_is_sent_when_starts_at_changes(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice', 'email' => 'alice@example.test']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $appointment = $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $this->actingAsTenantUser($user)
            ->putJson("/api/appointments/update/{$appointment->id}", [
                'starts_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertJsonPath('status.code', 200);

        Mail::assertSent(AppointmentRescheduledMail::class);
        Mail::assertNotSent(AppointmentCancelledMail::class);
    }

    public function test_no_lifecycle_email_sent_when_only_notes_are_updated(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner();
        [$client, $service] = $this->asTenant($business, fn () => [
            Client::create(['name' => 'Alice', 'email' => 'alice@example.test']),
            Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]),
        ]);

        $appointment = $this->asTenant($business, fn () => Appointment::create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'price' => $service->price,
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $this->actingAsTenantUser($user)
            ->putJson("/api/appointments/update/{$appointment->id}", ['notes' => 'Client called to confirm'])
            ->assertJsonPath('status.code', 200);

        Mail::assertNotSent(AppointmentCancelledMail::class);
        Mail::assertNotSent(AppointmentRescheduledMail::class);
    }
}
