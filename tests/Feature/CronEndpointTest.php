<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CronEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_is_disabled_without_a_configured_secret(): void
    {
        config(['services.cron.secret' => null]);

        $this->withHeader('Authorization', 'Bearer ')->getJson('/api/cron/reminders')->assertStatus(401);
    }

    public function test_wrong_secret_is_rejected_and_right_secret_runs_reminders(): void
    {
        config(['services.cron.secret' => 'top-secret']);

        $this->withHeader('Authorization', 'Bearer nope')->getJson('/api/cron/reminders')->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer top-secret')->getJson('/api/cron/reminders')
            ->assertOk()
            ->assertJsonPath('data.output', 'Sent 0 appointment reminder(s).');
    }
}
