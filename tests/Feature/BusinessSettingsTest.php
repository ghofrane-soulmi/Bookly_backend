<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

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
}
