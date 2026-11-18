<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class AuthPermissionsTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function staffMember(): User
    {
        [$business] = $this->createBusinessWithOwner();

        return $this->asTenant($business, function () use ($business) {
            $u = User::factory()->create(['business_id' => $business->id, 'password' => 'secret123']);
            $u->assignRole(User::ROLE_STAFF);

            return $u;
        });
    }

    public function test_login_and_me_return_the_users_permissions(): void
    {
        $staff = $this->staffMember();

        $permissions = $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'secret123'])
            ->assertOk()
            ->json('permissions');

        $this->assertContains('appointments.manage', $permissions);
        $this->assertNotContains('staff.manage', $permissions);
        $this->assertNotContains('invoices.view', $permissions);

        $this->actingAsTenantUser($staff)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('permissions', $permissions);
    }

    public function test_owner_gets_every_permission_including_invoices(): void
    {
        [, $owner] = $this->createBusinessWithOwner();

        $permissions = $this->actingAsTenantUser($owner)->getJson('/api/auth/me')->json('permissions');

        $this->assertContains('invoices.manage', $permissions);
        $this->assertContains('roles.manage', $permissions);
    }

    public function test_staff_can_list_colleagues_for_booking_but_not_manage_them(): void
    {
        $staff = $this->staffMember();

        $this->actingAsTenantUser($staff)->getJson('/api/staff/list')->assertJsonPath('status.code', 200);
        $this->actingAsTenantUser($staff)->getJson("/api/staff/{$staff->id}/detail")->assertForbidden();
        $this->actingAsTenantUser($staff)->postJson('/api/staff/create', [])->assertForbidden();
    }
}
