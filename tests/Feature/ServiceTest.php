<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Services\Models\Service;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_service_can_be_created(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($user)->postJson('/api/services/create', [
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 35,
        ]);

        $response->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.name', 'Haircut');

        $this->assertDatabaseHas('services', ['name' => 'Haircut', 'price' => 35]);
    }

    public function test_service_create_validates_required_fields(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($user)->postJson('/api/services/create', [])
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    }

    public function test_service_list_returns_created_services(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $this->asTenant($business, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]));

        $response = $this->actingAsTenantUser($user)->getJson('/api/services/list');

        $response->assertJsonPath('status.code', 200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_service_can_be_updated(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $service = $this->asTenant($business, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]));

        $response = $this->actingAsTenantUser($user)
            ->putJson("/api/services/update/{$service->id}", ['price' => 25]);

        $response->assertJsonPath('status.code', 200)
            ->assertJsonPath('data.price', '25.00');

        $this->assertSame('25.00', $service->fresh()->price);
    }

    public function test_service_can_be_deleted(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $service = $this->asTenant($business, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]));

        $this->actingAsTenantUser($user)
            ->deleteJson("/api/services/delete/{$service->id}")
            ->assertJsonPath('status.code', 200);

        $this->assertNull($service->fresh());
    }
}
