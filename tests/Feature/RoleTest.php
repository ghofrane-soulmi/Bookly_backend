<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_role_list_only_returns_the_current_businesss_roles(): void
    {
        [, $ownerA] = $this->createBusinessWithOwner();
        $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($ownerA)->getJson('/api/roles/list');

        $response->assertJsonPath('status.code', 200);
        $names = collect($response->json('data'))->pluck('name')->sort()->values();
        $this->assertSame(['admin', 'owner', 'staff'], $names->all());
    }

    public function test_role_detail_cannot_be_read_across_tenants(): void
    {
        [, $ownerA] = $this->createBusinessWithOwner();
        [, $ownerB] = $this->createBusinessWithOwner();

        $foreignRoleId = $this->actingAsTenantUser($ownerB)
            ->getJson('/api/roles/list')
            ->json('data.0.id');

        $this->actingAsTenantUser($ownerA)
            ->getJson("/api/roles/{$foreignRoleId}/detail")
            ->assertJsonPath('status.code', 404);
    }

    public function test_owner_can_create_a_custom_role_and_assign_it_to_staff(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        $create = $this->actingAsTenantUser($owner)->postJson('/api/roles/create', [
            'name' => 'receptionist',
            'permissions' => ['appointments.view', 'appointments.manage', 'clients.view'],
        ]);

        $create->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.name', 'receptionist');

        $this->actingAsTenantUser($owner)->postJson('/api/staff/create', [
            'name' => 'Rita',
            'email' => 'rita@example.test',
            'password' => 'password123',
            'role' => 'receptionist',
        ])
            ->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.role', 'receptionist');
    }

    public function test_built_in_roles_cannot_be_renamed_or_deleted(): void
    {
        [, $owner] = $this->createBusinessWithOwner();

        $ownerRoleId = $this->actingAsTenantUser($owner)
            ->getJson('/api/roles/list')
            ->json('data');
        $ownerRoleId = collect($ownerRoleId)->firstWhere('name', User::ROLE_OWNER)['id'];

        $this->actingAsTenantUser($owner)
            ->putJson("/api/roles/update/{$ownerRoleId}", ['name' => 'superowner'])
            ->assertJsonPath('status.code', 422);

        $this->actingAsTenantUser($owner)
            ->deleteJson("/api/roles/delete/{$ownerRoleId}")
            ->assertJsonPath('status.code', 422);
    }

    public function test_custom_role_cannot_be_deleted_while_staff_are_assigned(): void
    {
        [, $owner] = $this->createBusinessWithOwner();

        $roleId = $this->actingAsTenantUser($owner)->postJson('/api/roles/create', [
            'name' => 'receptionist',
            'permissions' => ['clients.view'],
        ])->json('data.id');

        $this->actingAsTenantUser($owner)->postJson('/api/staff/create', [
            'name' => 'Rita',
            'email' => 'rita@example.test',
            'password' => 'password123',
            'role' => 'receptionist',
        ]);

        $this->actingAsTenantUser($owner)
            ->deleteJson("/api/roles/delete/{$roleId}")
            ->assertJsonPath('status.code', 422);
    }

    public function test_non_owner_cannot_manage_roles(): void
    {
        [, $owner] = $this->createBusinessWithOwner();

        $staff = $this->actingAsTenantUser($owner)->postJson('/api/staff/create', [
            'name' => 'Sam',
            'email' => 'sam@example.test',
            'password' => 'password123',
            'role' => User::ROLE_STAFF,
        ])->json('data');

        $staffUser = User::find($staff['id']);

        $this->actingAsTenantUser($staffUser)
            ->getJson('/api/roles/list')
            ->assertStatus(403);
    }

    public function test_permissions_list_only_shows_current_businesss_roles(): void
    {
        [, $ownerA] = $this->createBusinessWithOwner();
        $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($ownerA)->getJson('/api/permissions/list');

        $response->assertJsonPath('status.code', 200);

        $appointmentsView = collect($response->json('data'))->firstWhere('name', 'appointments.view');
        sort($appointmentsView['roles']);
        $this->assertSame(['admin', 'owner', 'staff'], $appointmentsView['roles']);
    }
}
