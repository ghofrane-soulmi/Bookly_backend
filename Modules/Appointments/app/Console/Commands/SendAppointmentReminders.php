<?php

namespace Modules\Appointments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Modules\Appointments\Mail\AppointmentReminderMail;
use Modules\Appointments\Models\Appointment;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = "Send reminder emails for appointments starting within each business's configured reminder lead time";

    /**
     * Businesses can configure any reminder_lead_hours (validated 1-168), so this
     * bounds the DB query to the widest possible window before filtering per
     * business in PHP against that business's own lead time.
     */
    protected const MAX_LEAD_HOURS = 168;

    public function handle(): int
    {
        // Deliberately cross-tenant: this sweeps every business's appointments in
        // one pass, so every relation query below needs its own explicit scope
        // bypass — TenantScope now fails closed, and bypassing only the top-level
        // Appointment query would NOT carry through to the eager-loaded client/
        // service/staff relations or the whereHas('client', ...) subquery, since
        // each builds a fresh query against its own model's global scopes.
        // (Business has no TenantScope at all — it's the tenant root — so it
        // needs no bypass.)
        $candidates = Appointment::withoutGlobalScopes()
            ->with([
                'client' => fn ($q) => $q->withoutGlobalScopes(),
                'service' => fn ($q) => $q->withoutGlobalScopes(),
                'staff' => fn ($q) => $q->withoutGlobalScopes(),
                'business',
            ])
            ->whereBetween('starts_at', [now(), now()->addHours(self::MAX_LEAD_HOURS + 1)])
            ->where('status', Appointment::STATUS_SCHEDULED)
            ->whereNull('reminder_sent_at')
            ->whereHas('client', fn ($q) => $q->withoutGlobalScopes()->whereNotNull('email'))
            ->whereHas('business', fn ($q) => $q->where('notify_reminder_email', true))
            ->get();

        $sent = 0;

        foreach ($candidates as $appointment) {
            $leadHours = $appointment->business->reminder_lead_hours;
            $windowStart = now()->addHours($leadHours - 1);
            $windowEnd = now()->addHours($leadHours + 1);

            if (! $appointment->starts_at->between($windowStart, $windowEnd)) {
                continue;
            }

            Mail::to($appointment->client->email)->send(new AppointmentReminderMail($appointment));
            $appointment->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info("Sent {$sent} appointment reminder(s).");

        return self::SUCCESS;
    }
}
