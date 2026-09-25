<?php

namespace Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Appointments\Mail\AppointmentCancelledMail;
use Modules\Appointments\Mail\AppointmentConfirmationMail;
use Modules\Appointments\Mail\AppointmentRescheduledMail;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Support\AppointmentConflictException;
use Modules\Auth\Models\User;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

class AppointmentController extends Controller
{
    use ReturnsServerErrorResponse;

    public function handleListAppointments(Request $request)
    {
        try {
            $query = Appointment::with(['client', 'service', 'staff'])->orderBy('starts_at');

            if ($request->filled('from')) {
                $query->where('starts_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
            }

            if ($request->filled('to')) {
                $query->where('starts_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
            }

            if ($request->filled('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->query('service_id'));
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->query('user_id'));
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->whereHas('client', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            }

            if (! $request->filled('page')) {
                return response()->json([
                    'data' => $query->get(),
                    'status' => ['message' => 'Appointments retrieved successfully', 'code' => 200],
                ]);
            }

            $paginator = $query->paginate((int) $request->query('per_page', 10));

            $statusCounts = Appointment::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return response()->json([
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'status_counts' => [
                        'total' => (int) $statusCounts->sum(),
                        'scheduled' => (int) ($statusCounts['scheduled'] ?? 0),
                        'completed' => (int) ($statusCounts['completed'] ?? 0),
                        'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
                    ],
                ],
                'status' => ['message' => 'Appointments retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleGetAppointment($id)
    {
        try {
            $appointment = Appointment::with(['client', 'service', 'staff'])->find($id);

            if (! $appointment) {
                return response()->json([
                    'status' => ['message' => 'Appointment not found', 'code' => 404],
                ]);
            }

            return response()->json([
                'data' => $appointment,
                'status' => ['message' => 'Appointment retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleCreateAppointment(Request $request)
    {
        try {
            $businessId = app(Tenant::class)->id();

            $validator = Validator::make($request->all(), [
                'client_id' => [
                    'required', 'integer',
                    Rule::exists('clients', 'id')->where('business_id', $businessId),
                ],
                'service_id' => [
                    'required', 'integer',
                    Rule::exists('services', 'id')->where('business_id', $businessId),
                ],
                'user_id' => [
                    'required', 'integer',
                    Rule::exists('users', 'id')->where('business_id', $businessId),
                ],
                'starts_at' => ['required', 'date'],
                'notes' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $data = $validator->validated();
            $service = Service::findOrFail($data['service_id']);
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = $this->resolveEndsAt($service, $startsAt);

            if ($this->violatesBusinessHours($startsAt, $endsAt)) {
                return response()->json([
                    'status' => ['message' => "This time is outside the business's operating hours.", 'code' => 422],
                ]);
            }

            try {
                $appointment = DB::transaction(function () use ($data, $service, $startsAt, $endsAt) {
                    // Lock the staff member's row for the rest of this transaction so
                    // two concurrent booking requests for the same staff member can't
                    // both pass the conflict check before either one has inserted its
                    // row — they're serialized here instead of racing.
                    User::where('id', $data['user_id'])->lockForUpdate()->firstOrFail();

                    if ($this->hasConflict($data['user_id'], $startsAt, $endsAt)) {
                        throw new AppointmentConflictException;
                    }

                    return Appointment::create([
                        ...$data,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        // Snapshotted from the service at booking time so later price
                        // changes on the service don't retroactively alter past bookings.
                        // MoneyCast derives currency_code from this Money object.
                        'price' => $service->price,
                    ]);
                });
            } catch (AppointmentConflictException) {
                return response()->json([
                    'status' => ['message' => 'This staff member already has an appointment during this time.', 'code' => 409],
                ]);
            }

            $appointment->load(['client', 'service', 'staff', 'business']);

            if ($appointment->client->email && $appointment->business->notify_confirmation_email) {
                Mail::to($appointment->client->email)->send(new AppointmentConfirmationMail($appointment));
            }

            return response()->json([
                'data' => $appointment,
                'status' => ['message' => 'Appointment created successfully', 'code' => 201],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleUpdateAppointment(Request $request, $id)
    {
        try {
            $appointment = Appointment::find($id);

            if (! $appointment) {
                return response()->json([
                    'status' => ['message' => 'Appointment not found', 'code' => 404],
                ]);
            }

            $businessId = app(Tenant::class)->id();

            $validator = Validator::make($request->all(), [
                'client_id' => [
                    'sometimes', 'required', 'integer',
                    Rule::exists('clients', 'id')->where('business_id', $businessId),
                ],
                'service_id' => [
                    'sometimes', 'required', 'integer',
                    Rule::exists('services', 'id')->where('business_id', $businessId),
                ],
                'user_id' => [
                    'sometimes', 'required', 'integer',
                    Rule::exists('users', 'id')->where('business_id', $businessId),
                ],
                'starts_at' => ['sometimes', 'required', 'date'],
                'status' => ['sometimes', 'required', Rule::in([
                    Appointment::STATUS_SCHEDULED,
                    Appointment::STATUS_COMPLETED,
                    Appointment::STATUS_CANCELLED,
                    Appointment::STATUS_NO_SHOW,
                ])],
                'notes' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $data = $validator->validated();
            $userId = $data['user_id'] ?? $appointment->user_id;
            $serviceId = $data['service_id'] ?? $appointment->service_id;
            $service = Service::findOrFail($serviceId);
            $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $appointment->starts_at;
            $endsAt = $this->resolveEndsAt($service, $startsAt);

            if (isset($data['starts_at']) && $this->violatesBusinessHours($startsAt, $endsAt)) {
                return response()->json([
                    'status' => ['message' => "This time is outside the business's operating hours.", 'code' => 422],
                ]);
            }

            $wasCancelled = $appointment->status === Appointment::STATUS_CANCELLED;
            $wasStartsAt = $appointment->starts_at;

            try {
                DB::transaction(function () use ($userId, $startsAt, $endsAt, $appointment, $data, $service) {
                    // See handleCreateAppointment: locking the staff row here closes
                    // the same race for reschedules/staff reassignment.
                    User::where('id', $userId)->lockForUpdate()->firstOrFail();

                    if ($this->hasConflict($userId, $startsAt, $endsAt, excludeId: $appointment->id)) {
                        throw new AppointmentConflictException;
                    }

                    $updateData = [
                        ...$data,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ];

                    // Only re-snapshot price/currency when the service itself is actually
                    // changing — an unrelated edit (e.g. notes) must not silently update
                    // an already-booked appointment to the service's current price.
                    if (isset($data['service_id'])) {
                        $updateData['price'] = $service->price;
                    }

                    $appointment->update($updateData);
                });
            } catch (AppointmentConflictException) {
                return response()->json([
                    'status' => ['message' => 'This staff member already has an appointment during this time.', 'code' => 409],
                ]);
            }

            $appointment->load(['client', 'service', 'staff', 'business']);

            if ($appointment->client->email && $appointment->business->notify_confirmation_email) {
                $justCancelled = $appointment->status === Appointment::STATUS_CANCELLED && ! $wasCancelled;
                $justRescheduled = ! $justCancelled && $appointment->status !== Appointment::STATUS_CANCELLED && ! $startsAt->equalTo($wasStartsAt);

                if ($justCancelled) {
                    Mail::to($appointment->client->email)->send(new AppointmentCancelledMail($appointment));
                } elseif ($justRescheduled) {
                    Mail::to($appointment->client->email)->send(new AppointmentRescheduledMail($appointment));
                }
            }

            return response()->json([
                'data' => $appointment,
                'status' => ['message' => 'Appointment updated successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleDeleteAppointment($id)
    {
        try {
            $appointment = Appointment::find($id);

            if (! $appointment) {
                return response()->json([
                    'status' => ['message' => 'Appointment not found', 'code' => 404],
                ]);
            }

            $appointment->delete();

            return response()->json([
                'status' => ['message' => 'Appointment deleted successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    protected function resolveEndsAt(Service $service, Carbon $startsAt): Carbon
    {
        return $startsAt->copy()->addMinutes($service->duration_minutes);
    }

    /**
     * A business with no operating_hours configured is treated as always open
     * (opt-in restriction), so existing businesses aren't retroactively broken.
     */
    protected function violatesBusinessHours(Carbon $startsAt, Carbon $endsAt): bool
    {
        $business = Business::find(app(Tenant::class)->id());

        if (! $business || ! $business->operating_hours) {
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

    protected function hasConflict(int $userId, Carbon $startsAt, Carbon $endsAt, ?int $excludeId = null): bool
    {
        return Appointment::where('user_id', $userId)
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}
