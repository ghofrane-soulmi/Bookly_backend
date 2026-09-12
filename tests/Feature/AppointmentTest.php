<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Appointments\Mail\AppointmentConfirmationMail;
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

        $this->assertDatabaseHas('appointments', [
            'client_id' => $client->id,
            'service_id' => $service->id,
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
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $this->actingAsTenantUser($user)
            ->deleteJson("/api/appointments/delete/{$appointment->id}")
            ->assertJsonPath('status.code', 200);

        $this->assertNull($appointment->fresh());
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
            'user_id' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
        ]));

        $response = $this->actingAsTenantUser($user)->getJson('/api/appointments/list');

        $response->assertJsonPath('status.code', 200);
        $this->assertCount(1, $response->json('data'));
    }
}
