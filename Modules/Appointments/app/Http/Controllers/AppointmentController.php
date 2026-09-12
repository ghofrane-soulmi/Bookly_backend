<?php

namespace Modules\Appointments\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Appointments\Mail\AppointmentConfirmationMail;
use Modules\Appointments\Models\Appointment;
use Modules\Services\Models\Service;
use Modules\Tenant\Support\Tenant;

class AppointmentController extends Controller
{
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
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
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
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
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
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = $this->resolveEndsAt($data['service_id'], $startsAt);

            if ($this->hasConflict($data['user_id'], $startsAt, $endsAt)) {
                return response()->json([
                    'status' => ['message' => 'This staff member already has an appointment during this time.', 'code' => 409],
                ]);
            }

            $appointment = Appointment::create([
                ...$data,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $appointment->load(['client', 'service', 'staff', 'business']);

            if ($appointment->client->email && $appointment->business->notify_confirmation_email) {
                Mail::to($appointment->client->email)->send(new AppointmentConfirmationMail($appointment));
            }

            return response()->json([
                'data' => $appointment,
                'status' => ['message' => 'Appointment created successfully', 'code' => 201],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
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
            $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $appointment->starts_at;
            $endsAt = $this->resolveEndsAt($serviceId, $startsAt);

            if ($this->hasConflict($userId, $startsAt, $endsAt, excludeId: $appointment->id)) {
                return response()->json([
                    'status' => ['message' => 'This staff member already has an appointment during this time.', 'code' => 409],
                ]);
            }

            $appointment->update([
                ...$data,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            return response()->json([
                'data' => $appointment->load(['client', 'service', 'staff']),
                'status' => ['message' => 'Appointment updated successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
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
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveEndsAt(int $serviceId, Carbon $startsAt): Carbon
    {
        $service = Service::findOrFail($serviceId);

        return $startsAt->copy()->addMinutes($service->duration_minutes);
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
