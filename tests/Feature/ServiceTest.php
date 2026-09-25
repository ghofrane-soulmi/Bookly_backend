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
            ->assertJsonPath('data.name', 'Haircut')
            ->assertJsonPath('data.price.amount', 3500)
            ->assertJsonPath('data.price.currency', 'USD');

        // Decimal-string input ("35" => $35.00) is converted to minor units
        // (3500) using the business's currency decimals — see Phase 0 Step 6.
        $this->assertDatabaseHas('services', ['name' => 'Haircut', 'price' => 3500]);
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
            ->assertJsonPath('data.price.amount', 2500)
            ->assertJsonPath('data.price.currency', 'USD');

        $this->assertSame(2500, $service->fresh()->price->getMinorAmount()->toInt());
    }

    public function test_service_price_uses_the_businesss_own_currency_and_decimals(): void
    {
        [, $user] = $this->createBusinessWithOwner(['currency_code' => 'TND']);

        $response = $this->actingAsTenantUser($user)->postJson('/api/services/create', [
            'name' => 'Massage',
            'duration_minutes' => 45,
            'price' => '12.500',
        ]);

        $response->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.price.amount', 12500)
            ->assertJsonPath('data.price.currency', 'TND');
    }

    public function test_service_price_with_extra_precision_rounds_rather_than_rejects(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($user)->postJson('/api/services/create', [
            'name' => 'Trim',
            'duration_minutes' => 15,
            'price' => '9.999',
        ]);

        $response->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.price.amount', 1000);
    }

    public function test_service_can_be_deleted(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $service = $this->asTenant($business, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]));

        $this->actingAsTenantUser($user)
            ->deleteJson("/api/services/delete/{$service->id}")
            ->assertJsonPath('status.code', 200);

        $this->assertSoftDeleted($service);
        $this->assertNull(Service::find($service->id));
    }
}
