<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Clients\Models\Client;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_client_can_be_created(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($user)->postJson('/api/clients/create', [
            'name' => 'Alice',
            'email' => 'alice@example.test',
        ]);

        $response->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.name', 'Alice');

        $this->assertDatabaseHas('clients', ['name' => 'Alice', 'email' => 'alice@example.test']);
    }

    public function test_client_create_validates_required_fields(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($user)->postJson('/api/clients/create', [])
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    }

    public function test_client_list_returns_created_clients(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $this->asTenant($business, fn () => Client::create(['name' => 'Alice']));

        $response = $this->actingAsTenantUser($user)->getJson('/api/clients/list');

        $response->assertJsonPath('status.code', 200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_client_can_be_updated(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $client = $this->asTenant($business, fn () => Client::create(['name' => 'Alice']));

        $response = $this->actingAsTenantUser($user)
            ->putJson("/api/clients/update/{$client->id}", ['phone' => '555-1234']);

        $response->assertJsonPath('status.code', 200)
            ->assertJsonPath('data.phone', '555-1234');

        $this->assertSame('555-1234', $client->fresh()->phone);
    }

    public function test_client_can_be_deleted(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $client = $this->asTenant($business, fn () => Client::create(['name' => 'Alice']));

        $this->actingAsTenantUser($user)
            ->deleteJson("/api/clients/delete/{$client->id}")
            ->assertJsonPath('status.code', 200);

        $this->assertNull($client->fresh());
    }
}
