<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_deleting_a_business_with_users_is_blocked_by_the_database(): void
    {
        // users.business_id is RESTRICT, not SET NULL: a user silently losing
        // its business_id would fail every tenant-scoped query once
        // TenantScope started failing closed instead of skipping the scope.
        [$business] = $this->createBusinessWithOwner();

        $this->expectException(QueryException::class);

        $business->delete();
    }

    public function test_business_defaults_have_notifications_enabled_with_24_hour_lead_time(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($user)
            ->getJson('/api/business/detail')
            ->assertJsonPath('data.notify_confirmation_email', true)
            ->assertJsonPath('data.notify_reminder_email', true)
            ->assertJsonPath('data.reminder_lead_hours', 24);
    }

    public function test_notification_settings_can_be_updated(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($user)->putJson('/api/business/update', [
            'notify_confirmation_email' => false,
            'notify_reminder_email' => true,
            'reminder_lead_hours' => 2,
        ]);

        $response->assertJsonPath('status.code', 200)
            ->assertJsonPath('data.notify_confirmation_email', false)
            ->assertJsonPath('data.reminder_lead_hours', 2);

        $this->assertSame(2, $business->fresh()->reminder_lead_hours);
        $this->assertFalse($business->fresh()->notify_confirmation_email);
    }

    public function test_reminder_lead_hours_must_be_within_valid_range(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($user)
            ->putJson('/api/business/update', ['reminder_lead_hours' => 0])
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.reminder_lead_hours.0', 'The reminder lead hours field must be at least 1.');

        $this->actingAsTenantUser($user)
            ->putJson('/api/business/update', ['reminder_lead_hours' => 200])
            ->assertJsonPath('status.code', 422)
            ->assertJsonPath('errors.reminder_lead_hours.0', 'The reminder lead hours field must not be greater than 168.');
    }

    public function test_operating_hours_default_to_null_meaning_always_open(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $this->actingAsTenantUser($user)
            ->getJson('/api/business/detail')
            ->assertJsonPath('data.operating_hours', null);
    }

    public function test_operating_hours_can_be_set(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $hours = [
            'monday' => ['open' => '09:00', 'close' => '18:00'],
            'tuesday' => ['open' => '09:00', 'close' => '18:00'],
            'wednesday' => null,
            'thursday' => null,
            'friday' => null,
            'saturday' => null,
            'sunday' => null,
        ];

        $this->actingAsTenantUser($user)
            ->putJson('/api/business/update', ['operating_hours' => $hours])
            ->assertJsonPath('status.code', 200)
            ->assertJsonPath('data.operating_hours.monday.open', '09:00');

        $this->assertSame('18:00', $business->fresh()->operating_hours['monday']['close']);
    }

    public function test_operating_hours_close_must_be_after_open(): void
    {
        [, $user] = $this->createBusinessWithOwner();

        $response = $this->actingAsTenantUser($user)
            ->putJson('/api/business/update', [
                'operating_hours' => [
                    'monday' => ['open' => '18:00', 'close' => '09:00'],
                ],
            ]);

        $response->assertJsonPath('status.code', 422);
        $this->assertArrayHasKey('operating_hours.monday.close', $response->json('errors'));
    }
}
