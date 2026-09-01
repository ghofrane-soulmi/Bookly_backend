<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_register_creates_business_owner_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'business_name' => 'Glow Salon',
            'name' => 'Jane Owner',
            'email' => 'jane@glow.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'jane@glow.test')
            ->assertJsonPath('user.role', User::ROLE_OWNER)
            ->assertJsonPath('business.name', 'Glow Salon')
            ->assertJsonStructure(['token', 'token_type', 'expires_in']);

        $this->assertDatabaseHas('businesses', ['name' => 'Glow Salon']);
        $this->assertDatabaseHas('users', ['email' => 'jane@glow.test', 'role' => User::ROLE_OWNER]);
    }

    public function test_register_rejects_missing_required_fields(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_name', 'name', 'email', 'password']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        [, $existingUser] = $this->createBusinessWithOwner();

        $this->postJson('/api/auth/register', [
            'business_name' => 'Another Salon',
            'name' => 'Someone',
            'email' => $existingUser->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        [$business, $user] = $this->createBusinessWithOwner([], [
            'password' => 'password123',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])
            ->assertStatus(200)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('business.id', $business->id)
            ->assertJsonStructure(['token', 'token_type', 'expires_in']);
    }

    public function test_login_with_invalid_credentials_is_rejected(): void
    {
        [, $user] = $this->createBusinessWithOwner([], [
            'password' => 'password123',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(401);
    }

    public function test_protected_route_requires_token(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_protected_route_works_with_valid_token(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($user)
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_forgot_password_creates_reset_token_for_existing_user(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertJsonPath('status.code', 200);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_does_not_reveal_whether_email_exists(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@nowhere.test']);

        $response->assertJsonPath('status.code', 200);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@nowhere.test']);
    }

    public function test_reset_password_with_valid_token_updates_password(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'valid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertJsonPath('status.code', 200);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'wrong-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertJsonPath('status.code', 422);
    }

    public function test_reset_password_rejects_expired_token(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now()->subMinutes(61),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'valid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertJsonPath('status.code', 422);
    }

    public function test_reset_password_token_cannot_be_reused(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'valid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertJsonPath('status.code', 200);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'valid-token',
            'password' => 'anotherpassword123',
            'password_confirmation' => 'anotherpassword123',
        ])->assertJsonPath('status.code', 422);
    }
}
