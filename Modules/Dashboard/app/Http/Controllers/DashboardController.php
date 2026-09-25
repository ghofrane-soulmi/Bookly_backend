<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Modules\Appointments\Models\Appointment;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $currencyCode = Business::find(app(Tenant::class)->id())->currency_code;

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();
        $lastMonthStart = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonthNoOverflow()->endOfMonth();
        $rangeStart = Carbon::today()->subDays(29);

        $todayAppointments = Appointment::whereDate('starts_at', $today)
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->count();

        $upcomingAppointments = Appointment::whereBetween('starts_at', [now(), now()->addDays(7)])
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->count();

        $revenueThisMonth = (int) Appointment::query()
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereBetween('starts_at', [$monthStart, $monthEnd])
            ->sum('price');

        $revenueLastMonth = (int) Appointment::query()
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereBetween('starts_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('price');

        $revenueChangePercent = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : null;

        $bookingsByDayRaw = Appointment::query()
            ->selectRaw('DATE(starts_at) as booking_date, COUNT(*) as total')
            ->where('starts_at', '>=', $rangeStart)
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->groupBy('booking_date')
            ->pluck('total', 'booking_date');

        $bookingsByDay = collect(range(0, 29))->map(function ($offset) use ($rangeStart, $bookingsByDayRaw) {
            $date = $rangeStart->copy()->addDays($offset)->toDateString();

            return ['date' => $date, 'count' => (int) ($bookingsByDayRaw[$date] ?? 0)];
        });

        $sixMonthsStart = Carbon::now()->subMonthsNoOverflow(5)->startOfMonth();

        // Grouped in PHP rather than via DATE_FORMAT() so this doesn't depend on
        // MySQL-specific SQL (keeps it portable to the SQLite test database).
        // Uses the appointment's own snapshotted price (not the service's
        // current price) — see Phase 0 Step 5.
        $revenueByMonthRaw = Appointment::query()
            ->where('status', Appointment::STATUS_COMPLETED)
            ->where('starts_at', '>=', $sixMonthsStart)
            ->get(['starts_at', 'price', 'currency_code'])
            ->groupBy(fn ($appointment) => Carbon::parse($appointment->starts_at)->format('Y-m'))
            ->map(fn ($group) => (int) $group->sum(fn ($appointment) => $appointment->price->getMinorAmount()->toInt()));

        $revenueByMonth = collect(range(0, 5))->map(function ($offset) use ($sixMonthsStart, $revenueByMonthRaw, $currencyCode) {
            $month = $sixMonthsStart->copy()->addMonthsNoOverflow($offset);

            return [
                'month' => $month->format('Y-m'),
                'revenue' => ['amount' => (int) ($revenueByMonthRaw[$month->format('Y-m')] ?? 0), 'currency' => $currencyCode],
            ];
        });

        $topServices = Appointment::query()
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->where('appointments.status', '!=', Appointment::STATUS_CANCELLED)
            ->selectRaw('services.name, COUNT(*) as total')
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $busiestStaff = Appointment::query()
            ->join('users', 'users.id', '=', 'appointments.user_id')
            ->where('appointments.status', '!=', Appointment::STATUS_CANCELLED)
            ->selectRaw('users.name, COUNT(*) as total')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $recentAppointments = Appointment::with(['client', 'service'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($appointment) => [
                'id' => $appointment->id,
                'client_name' => $appointment->client->name,
                'service_name' => $appointment->service->name,
                'status' => $appointment->status,
                'starts_at' => $appointment->starts_at,
            ]);

        $recentActivity = collect()
            ->concat(
                Appointment::with('client')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($a) => [
                        'type' => 'appointment_booked',
                        'message' => "Appointment booked for {$a->client->name}",
                        'at' => $a->created_at,
                    ])
            )
            ->concat(
                Client::orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($c) => [
                        'type' => 'client_added',
                        'message' => "New client added: {$c->name}",
                        'at' => $c->created_at,
                    ])
            )
            ->sortByDesc('at')
            ->values()
            ->take(6);

        return response()->json([
            'data' => [
                'today_appointments' => $todayAppointments,
                'upcoming_appointments' => $upcomingAppointments,
                'total_clients' => Client::count(),
                'total_services' => Service::count(),
                'revenue_this_month' => ['amount' => $revenueThisMonth, 'currency' => $currencyCode],
                'revenue_change_percent' => $revenueChangePercent,
                'bookings_by_day' => $bookingsByDay,
                'revenue_by_month' => $revenueByMonth,
                'top_services' => $topServices,
                'busiest_staff' => $busiestStaff,
                'recent_appointments' => $recentAppointments,
                'recent_activity' => $recentActivity,
            ],
        ]);
    }
}
