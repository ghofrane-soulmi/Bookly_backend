<?php

namespace Modules\Appointments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Modules\Appointments\Mail\AppointmentReminderMail;
use Modules\Appointments\Models\Appointment;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Send reminder emails for appointments starting in about 24 hours';

    public function handle(): int
    {
        $windowStart = now()->addHours(23);
        $windowEnd = now()->addHours(25);

        $appointments = Appointment::withoutGlobalScopes()
            ->with(['client', 'service', 'staff', 'business'])
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->whereNull('reminder_sent_at')
            ->whereHas('client', fn ($q) => $q->whereNotNull('email'))
            ->get();

        foreach ($appointments as $appointment) {
            Mail::to($appointment->client->email)->send(new AppointmentReminderMail($appointment));
            $appointment->forceFill(['reminder_sent_at' => now()])->save();
        }

        $this->info("Sent {$appointments->count()} appointment reminder(s).");

        return self::SUCCESS;
    }
}
