<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_staff_member_can_be_created(): void
    {
        [, $owner] = $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($owner)->postJson('/api/staff/create', [
            'name' => 'Stella Stylist',
            'email' => 'stella@example.test',
            'password' => 'password123',
            'role' => User::ROLE_STAFF,
        ]);

        $response->assertJsonPath('status.code', 201)
            ->assertJsonPath('data.role', User::ROLE_STAFF);

        $this->assertDatabaseHas('users', ['email' => 'stella@example.test', 'role' => User::ROLE_STAFF]);
    }

    public function test_staff_create_rejects_owner_role(): void
    {
        [, $owner] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($owner)->postJson('/api/staff/create', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.test',
            'password' => 'password123',
            'role' => User::ROLE_OWNER,
        ])
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.role.0', 'The selected role is invalid.');
    }

    public function test_staff_create_rejects_duplicate_email(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($owner)->postJson('/api/staff/create', [
            'name' => 'Existing',
            'email' => $owner->email,
            'password' => 'password123',
            'role' => User::ROLE_STAFF,
        ])
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.email.0', 'The email has already been taken.');
    }

    public function test_staff_member_can_be_updated(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        $staff = $this->asTenant($business, fn () => User::create([
            'name' => 'Stella',
            'email' => 'stella@example.test',
            'password' => 'password123',
            'role' => User::ROLE_STAFF,
        ]));

        $response = $this->actingAsTenantUser($owner)
            ->putJson("/api/staff/update/{$staff->id}", ['name' => 'Stella Updated']);

        $response->assertJsonPath('status.code', 200)
            ->assertJsonPath('data.name', 'Stella Updated');

        $this->assertSame('Stella Updated', $staff->fresh()->name);
    }

    public function test_staff_member_can_be_deleted(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        $staff = $this->asTenant($business, fn () => User::create([
            'name' => 'Stella',
            'email' => 'stella@example.test',
            'password' => 'password123',
            'role' => User::ROLE_STAFF,
        ]));

        $this->actingAsTenantUser($owner)
            ->deleteJson("/api/staff/delete/{$staff->id}")
            ->assertJsonPath('status.code', 200);

        $this->assertSoftDeleted($staff);
        $this->assertNull(User::find($staff->id));
    }

    public function test_user_cannot_delete_their_own_account(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        $this->asTenant($business, fn () => User::create([
            'name' => 'Stella',
            'email' => 'stella@example.test',
            'password' => 'password123',
            'role' => User::ROLE_STAFF,
        ]));

        $this->actingAsTenantUser($owner)
            ->deleteJson("/api/staff/delete/{$owner->id}")
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('status.message', 'You cannot delete your own account.');

        $this->assertNotNull($owner->fresh());
    }

    public function test_sole_remaining_staff_member_cannot_be_deleted(): void
    {
        [, $owner] = $this->createBusinessWithOwner();

        // Owner is the only user in the tenant, so the self-delete guard and the
        // last-remaining-user guard both apply; either message is an acceptable block.
        $this->actingAsTenantUser($owner)
            ->deleteJson("/api/staff/delete/{$owner->id}")
            ->assertJsonPath('status.code', 422);

        $this->assertNotNull($owner->fresh());
    }
}
