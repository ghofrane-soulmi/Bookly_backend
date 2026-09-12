<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Appointments\Mail\AppointmentReminderMail;
use Modules\Appointments\Models\Appointment;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Modules\Tenant\Support\Tenant;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class SendAppointmentRemindersTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    /**
     * A real cron invocation of this command runs in a fresh CLI process, so the
     * Tenant singleton has never been set. Test setup helpers (createBusinessWithOwner,
     * asTenant) leave stale tenant context behind in this same PHP process, which would
     * otherwise silently scope the command's whereHas(...) subqueries to the wrong
     * tenant — so tenant context is reset before invoking the command to match reality.
     */
    protected function runReminderCommand(): void
    {
        app(Tenant::class)->set(null);
        $this->artisan('appointments:send-reminders');
    }

    protected function makeAppointment(mixed $business, mixed $user, Carbon $startsAt): Appointment
    {
        return $this->asTenant($business, function () use ($user, $startsAt) {
            $client = Client::create(['name' => 'Alice', 'email' => 'alice@example.test']);
            $service = Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]);

            return Appointment::create([
                'client_id' => $client->id,
                'service_id' => $service->id,
                'user_id' => $user->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(30),
                'status' => Appointment::STATUS_SCHEDULED,
            ]);
        });
    }

    public function test_reminder_is_sent_within_businesss_configured_lead_time(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['reminder_lead_hours' => 2]);
        $appointment = $this->makeAppointment($business, $user, now()->addHours(2));

        $this->runReminderCommand();

        Mail::assertSent(AppointmentReminderMail::class);
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_reminder_is_not_sent_outside_businesss_configured_lead_time(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner(['reminder_lead_hours' => 2]);
        $appointment = $this->makeAppointment($business, $user, now()->addHours(48));

        $this->runReminderCommand();

        Mail::assertNotSent(AppointmentReminderMail::class);
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_reminder_is_not_sent_when_business_disables_reminder_email(): void
    {
        Mail::fake();

        [$business, $user] = $this->createBusinessWithOwner([
            'reminder_lead_hours' => 24,
            'notify_reminder_email' => false,
        ]);
        $appointment = $this->makeAppointment($business, $user, now()->addHours(24));

        $this->runReminderCommand();

        Mail::assertNotSent(AppointmentReminderMail::class);
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_each_business_uses_its_own_lead_time_independently(): void
    {
        Mail::fake();

        [$businessA, $userA] = $this->createBusinessWithOwner(['reminder_lead_hours' => 1]);
        [$businessB, $userB] = $this->createBusinessWithOwner(['reminder_lead_hours' => 48]);

        $dueSoonA = $this->makeAppointment($businessA, $userA, now()->addHour());
        $notDueB = $this->makeAppointment($businessB, $userB, now()->addHour());

        $this->runReminderCommand();

        $this->assertNotNull($dueSoonA->fresh()->reminder_sent_at);
        $this->assertNull($notDueB->fresh()->reminder_sent_at);
    }
}
