<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Appointments\Models\Appointment;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_business_cannot_view_another_businesss_service(): void
    {
        [$businessA] = $this->createBusinessWithOwner();
        [, $userB] = $this->createBusinessWithOwner();

        $service = $this->asTenant($businessA, fn () => Service::create([
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 35,
        ]));

        $this->actingAsTenantUser($userB)
            ->getJson("/api/services/{$service->id}/detail")
            ->assertJsonPath('status.code', 404);
    }

    public function test_business_cannot_update_another_businesss_service(): void
    {
        [$businessA] = $this->createBusinessWithOwner();
        [, $userB] = $this->createBusinessWithOwner();

        $service = $this->asTenant($businessA, fn () => Service::create([
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 35,
        ]));

        $this->actingAsTenantUser($userB)
            ->putJson("/api/services/update/{$service->id}", ['name' => 'Hijacked'])
            ->assertJsonPath('status.code', 404);

        $this->assertSame('Haircut', $service->fresh()->name);
    }

    public function test_business_cannot_delete_another_businesss_service(): void
    {
        [$businessA] = $this->createBusinessWithOwner();
        [, $userB] = $this->createBusinessWithOwner();

        $service = $this->asTenant($businessA, fn () => Service::create([
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 35,
        ]));

        $this->actingAsTenantUser($userB)
            ->deleteJson("/api/services/delete/{$service->id}")
            ->assertJsonPath('status.code', 404);

        $this->assertNotNull($service->fresh());
    }

    public function test_services_list_only_shows_own_businesss_services(): void
    {
        [$businessA, $userA] = $this->createBusinessWithOwner();
        [$businessB] = $this->createBusinessWithOwner();

        $this->asTenant($businessA, fn () => Service::create(['name' => 'Mine', 'duration_minutes' => 30, 'price' => 10]));
        $this->asTenant($businessB, fn () => Service::create(['name' => 'TheirsNotMine', 'duration_minutes' => 30, 'price' => 10]));

        $response = $this->actingAsTenantUser($userA)->getJson('/api/services/list');

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('TheirsNotMine'));
    }

    public function test_business_cannot_view_another_businesss_client(): void
    {
        [$businessA] = $this->createBusinessWithOwner();
        [, $userB] = $this->createBusinessWithOwner();

        $client = $this->asTenant($businessA, fn () => Client::create(['name' => 'Secret Client']));

        $this->actingAsTenantUser($userB)
            ->getJson("/api/clients/{$client->id}/detail")
            ->assertJsonPath('status.code', 404);
    }

    public function test_business_cannot_view_another_businesss_appointment(): void
    {
        [$businessA, $userA] = $this->createBusinessWithOwner();
        [, $userB] = $this->createBusinessWithOwner();

        $appointment = $this->asTenant($businessA, function () use ($userA) {
            $client = Client::create(['name' => 'Client A']);
            $service = Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 10]);

            return Appointment::create([
                'client_id' => $client->id,
                'service_id' => $service->id,
                'user_id' => $userA->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addMinutes(30),
                'status' => Appointment::STATUS_SCHEDULED,
            ]);
        });

        $this->actingAsTenantUser($userB)
            ->getJson("/api/appointments/{$appointment->id}/detail")
            ->assertJsonPath('status.code', 404);
    }

    public function test_appointment_cannot_be_created_referencing_another_businesss_client(): void
    {
        [$businessA] = $this->createBusinessWithOwner();
        [$businessB, $userB] = $this->createBusinessWithOwner();

        $foreignClient = $this->asTenant($businessA, fn () => Client::create(['name' => 'Not Yours']));
        $ownService = $this->asTenant($businessB, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 10]));

        $this->actingAsTenantUser($userB)
            ->postJson('/api/appointments/create', [
                'client_id' => $foreignClient->id,
                'service_id' => $ownService->id,
                'user_id' => $userB->id,
                'starts_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.client_id.0', 'The selected client id is invalid.');
    }

    public function test_business_cannot_view_another_businesss_staff_member(): void
    {
        [$businessA, $ownerA] = $this->createBusinessWithOwner();
        [, $userB] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($userB)
            ->getJson("/api/staff/{$ownerA->id}/detail")
            ->assertJsonPath('status.code', 404);
    }

    public function test_dashboard_summary_only_counts_own_businesss_data(): void
    {
        [$businessA, $userA] = $this->createBusinessWithOwner();
        [$businessB] = $this->createBusinessWithOwner();

        $this->asTenant($businessA, fn () => Client::create(['name' => 'A Client']));
        $this->asTenant($businessB, function () {
            Client::create(['name' => 'B Client 1']);
            Client::create(['name' => 'B Client 2']);
        });

        $response = $this->actingAsTenantUser($userA)->getJson('/api/dashboard/summary');

        $response->assertJsonPath('data.total_clients', 1);
    }
}
