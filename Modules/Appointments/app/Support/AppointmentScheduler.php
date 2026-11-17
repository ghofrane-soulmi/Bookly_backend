<?php

namespace Modules\Appointments\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Appointments\Models\Appointment;
use Modules\Auth\Models\User;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;

/**
 * Scheduling rules shared by the back-office (staff) and the front-office
 * (customer) booking flows, so both enforce exactly the same constraints.
 *
 * Every Appointment query here relies on the tenant context already being set
 * to $business — callers are responsible for that.
 */
class AppointmentScheduler
{
    /** Minutes between candidate start times offered to customers. */
    public const SLOT_STEP_MINUTES = 15;

    /**
     * A datetime without an explicit offset ("2026-11-20 14:00") is wall-clock
     * time in the business's timezone; one with an offset is honoured as-is.
     * The result is always converted to the app timezone (UTC), because
     * Eloquent formats datetimes for storage without converting them.
     */
    public function parseStartsAt(Business $business, string $value): Carbon
    {
        return Carbon::parse($value, $business->timezone ?: 'UTC')
            ->setTimezone(config('app.timezone'));
    }

    public function endsAt(Service $service, Carbon $startsAt): Carbon
    {
        return $startsAt->copy()->addMinutes($service->duration_minutes);
    }

    /**
     * A business with no operating_hours configured is treated as always open
     * (opt-in restriction), so existing businesses aren't retroactively broken.
     */
    public function violatesBusinessHours(Business $business, Carbon $startsAt, Carbon $endsAt): bool
    {
        if (! $business->operating_hours) {
            return false;
        }

        $localStart = $startsAt->copy()->setTimezone($business->timezone);
        $localEnd = $endsAt->copy()->setTimezone($business->timezone);

        $day = strtolower($localStart->format('l'));
        $hours = $business->operating_hours[$day] ?? null;

        if (empty($hours['open']) || empty($hours['close'])) {
            return true;
        }

        $open = $localStart->copy()->setTimeFromTimeString($hours['open']);
        $close = $localStart->copy()->setTimeFromTimeString($hours['close']);

        return $localStart->lt($open)
            || $localEnd->gt($close)
            || $localEnd->format('Y-m-d') !== $localStart->format('Y-m-d');
    }

    public function hasConflict(int $userId, Carbon $startsAt, Carbon $endsAt, ?int $excludeId = null): bool
    {
        return Appointment::where('user_id', $userId)
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    /**
     * Free start times for $service on $date (Y-m-d, business-local), per staff
     * member. Returns [['starts_at' => ISO-8601 UTC, 'user_ids' => int[]], ...]
     * so the UI can offer "any staff" or filter by a specific one.
     *
     * @param  Collection<int, User>  $staff
     * @return array<int, array{starts_at: string, user_ids: array<int, int>}>
     */
    public function availableSlots(Business $business, Service $service, string $date, Collection $staff): array
    {
        if ($staff->isEmpty()) {
            return [];
        }

        $tz = $business->timezone ?: 'UTC';
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();

        if ($business->operating_hours) {
            $hours = $business->operating_hours[strtolower($day->format('l'))] ?? null;

            if (empty($hours['open']) || empty($hours['close'])) {
                return [];
            }

            $windowStart = $day->setTimeFromTimeString($hours['open']);
            $windowEnd = $day->setTimeFromTimeString($hours['close']);
        } else {
            $windowStart = $day;
            $windowEnd = $day->addDay();
        }

        $busy = Appointment::whereIn('user_id', $staff->pluck('id'))
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->where('starts_at', '<', $windowEnd->utc())
            ->where('ends_at', '>', $windowStart->utc())
            ->get(['user_id', 'starts_at', 'ends_at'])
            ->groupBy('user_id');

        // No slots in the past (plus a small buffer so a customer can't book
        // something that starts while they're still filling in the form).
        $earliest = CarbonImmutable::now()->addMinutes(self::SLOT_STEP_MINUTES);

        $slots = [];

        for (
            $start = $windowStart;
            $start->addMinutes($service->duration_minutes)->lte($windowEnd);
            $start = $start->addMinutes(self::SLOT_STEP_MINUTES)
        ) {
            if ($start->lt($earliest)) {
                continue;
            }

            $end = $start->addMinutes($service->duration_minutes);

            $free = $staff->filter(function (User $member) use ($busy, $start, $end) {
                return ! ($busy->get($member->id) ?? collect())->contains(
                    fn (Appointment $a) => $a->starts_at->lt($end) && $a->ends_at->gt($start)
                );
            });

            if ($free->isNotEmpty()) {
                $slots[] = [
                    'starts_at' => $start->utc()->toIso8601String(),
                    'user_ids' => $free->pluck('id')->values()->all(),
                ];
            }
        }

        return $slots;
    }
}
