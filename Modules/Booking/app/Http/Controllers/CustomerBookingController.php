<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Modules\Appointments\Mail\AppointmentCancelledMail;
use Modules\Appointments\Mail\AppointmentConfirmationMail;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Support\AppointmentConflictException;
use Modules\Appointments\Support\AppointmentScheduler;
use Modules\Auth\Models\User;
use Modules\Booking\Http\Controllers\Concerns\RespondsWithEnvelope;
use Modules\Booking\Models\Customer;
use Modules\Booking\Support\ResolvesPublicBusiness;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Models\Scopes\TenantScope;
use Modules\Tenant\Support\Tenant;

class CustomerBookingController extends Controller
{
    use ResolvesPublicBusiness, RespondsWithEnvelope;

    public function __construct(protected AppointmentScheduler $scheduler) {}

    /**
     * The customer's bookings across every business, newest first.
     * ?scope=upcoming|past narrows it.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $appointments = $this->customerAppointments()
                ->when($request->query('scope') === 'upcoming', fn ($q) => $q
                    ->where('starts_at', '>=', now())
                    ->where('status', Appointment::STATUS_SCHEDULED)
                    ->reorder('starts_at'))
                ->when($request->query('scope') === 'past', fn ($q) => $q
                    ->where(fn ($q) => $q->where('starts_at', '<', now())
                        ->orWhere('status', '!=', Appointment::STATUS_SCHEDULED)))
                ->get();

            return $this->envelope($appointments->map(fn ($a) => $this->present($a))->values(), 'Appointments retrieved');
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        try {
            /** @var Customer $customer */
            $customer = auth('customer')->user();
            $business = $this->resolveBusiness($slug);

            if (! $business) {
                return $this->envelope(null, 'Business not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'service_id' => ['required', 'integer'],
                'user_id' => ['nullable', 'integer'],
                'starts_at' => ['required', 'date'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            if ($validator->fails()) {
                return $this->validationError($validator->errors()->toArray());
            }

            $data = $validator->validated();
            $service = Service::where('is_active', true)->find($data['service_id']);

            if (! $service) {
                return $this->validationError(['service_id' => ['This service is not available.']]);
            }

            if (isset($data['user_id']) && ! User::whereKey($data['user_id'])->exists()) {
                return $this->validationError(['user_id' => ['This staff member is not available.']]);
            }

            $startsAt = $this->scheduler->parseStartsAt($business, $data['starts_at']);
            $endsAt = $this->scheduler->endsAt($service, $startsAt);

            if ($startsAt->lt(now()) || $startsAt->gt(now()->addDays(config('booking.max_days_ahead') + 1))) {
                return $this->envelope(null, 'This time can no longer be booked.', 422);
            }

            if ($this->scheduler->violatesBusinessHours($business, $startsAt, $endsAt)) {
                return $this->envelope(null, "This time is outside the business's opening hours.", 422);
            }

            try {
                $appointment = DB::transaction(function () use ($customer, $business, $service, $data, $startsAt, $endsAt) {
                    $staffId = $this->pickStaff($data['user_id'] ?? null, $startsAt, $endsAt);
                    $client = $this->clientFor($customer, $business);

                    return Appointment::create([
                        'client_id' => $client->id,
                        'service_id' => $service->id,
                        'user_id' => $staffId,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'notes' => $data['notes'] ?? null,
                        // Explicit (not left to the column default) so the
                        // response reflects it without a re-fetch.
                        'status' => Appointment::STATUS_SCHEDULED,
                        'price' => $service->price,
                    ]);
                });
            } catch (AppointmentConflictException) {
                return $this->envelope(null, 'Sorry, this time was just taken. Please pick another slot.', 409);
            }

            $appointment->load(['client', 'service', 'staff', 'business']);

            if ($appointment->client->email && $business->notify_confirmation_email) {
                $this->sendSafely($appointment->client->email, new AppointmentConfirmationMail($appointment));
            }

            return $this->envelope($this->present($appointment), 'Appointment booked', 201);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function cancel(int $id): JsonResponse
    {
        try {
            $appointment = $this->customerAppointments()->find($id);

            if (! $appointment) {
                return $this->envelope(null, 'Appointment not found', 404);
            }

            if ($appointment->status !== Appointment::STATUS_SCHEDULED) {
                return $this->envelope(null, 'Only upcoming appointments can be cancelled.', 422);
            }

            $cutoff = config('booking.cancel_cutoff_hours');

            if ($appointment->starts_at->lt(now()->addHours($cutoff))) {
                return $this->envelope(null, "Appointments can only be cancelled online up to {$cutoff} hours before they start. Please contact the business.", 422);
            }

            // Mailables and model events below expect the tenant context.
            app(Tenant::class)->set($appointment->business_id);

            $appointment->update(['status' => Appointment::STATUS_CANCELLED]);
            $appointment->load(['client', 'service', 'staff', 'business']);

            if ($appointment->client->email && $appointment->business->notify_confirmation_email) {
                $this->sendSafely($appointment->client->email, new AppointmentCancelledMail($appointment));
            }

            return $this->envelope($this->present($appointment), 'Appointment cancelled');
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * The booking is already committed when this runs, so a mail outage must
     * not turn a successful booking into an error (the customer would retry
     * and hit a 409 on their own slot). Log it and carry on.
     */
    protected function sendSafely(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Appointments belonging to the signed-in customer, across all tenants.
     * The tenant scope is lifted explicitly and replaced by the ownership
     * filter (client.customer_id), which is the real access boundary here.
     */
    protected function customerAppointments(): Builder
    {
        $customerId = auth('customer')->id();
        $unscoped = fn ($q) => $q->withoutGlobalScope(TenantScope::class);

        return Appointment::withoutGlobalScope(TenantScope::class)
            ->whereIn('client_id', Client::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->where('customer_id', $customerId)
                ->select('id'))
            ->with([
                'service' => $unscoped,
                'staff' => $unscoped,
                'business',
            ])
            ->orderByDesc('starts_at');
    }

    /**
     * Requested staff member, or — for "any staff" — the first one free.
     * Locks the chosen staff row so concurrent bookings serialize (same
     * approach as the back-office AppointmentController).
     */
    protected function pickStaff(?int $userId, Carbon $startsAt, Carbon $endsAt): int
    {
        $candidates = $userId ? [$userId] : User::orderBy('id')->pluck('id')->all();

        foreach ($candidates as $id) {
            User::whereKey($id)->lockForUpdate()->first();

            if (! $this->scheduler->hasConflict($id, $startsAt, $endsAt)) {
                return $id;
            }
        }

        throw new AppointmentConflictException;
    }

    /**
     * The business's client record for this customer: the linked one, else an
     * unlinked client with the same email (e.g. added earlier by staff), else
     * a new one. Restores a soft-deleted record rather than duplicating it.
     */
    protected function clientFor(Customer $customer, Business $business): Client
    {
        $client = Client::withTrashed()->where('customer_id', $customer->id)->first()
            ?? Client::withTrashed()->whereNull('customer_id')->where('email', $customer->email)->first();

        if (! $client) {
            $client = new Client(['name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone]);
        }

        $client->customer_id = $customer->id;

        if ($client->trashed()) {
            $client->restore();
        }

        $client->save();

        return $client;
    }

    /**
     * Customer-safe shape: no internal notes, no client record, no staff email.
     *
     * @return array<string, mixed>
     */
    protected function present(Appointment $a): array
    {
        $cutoff = config('booking.cancel_cutoff_hours');

        return [
            'id' => $a->id,
            'status' => $a->status,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            // Same shape as SerializesMoneyAttributes: minor units + currency.
            'price' => $a->price ? [
                'amount' => $a->price->getMinorAmount()->toInt(),
                'currency' => $a->price->getCurrency()->getCurrencyCode(),
            ] : null,
            'can_cancel' => $a->status === Appointment::STATUS_SCHEDULED && $a->starts_at->gte(now()->addHours($cutoff)),
            'business' => $a->business?->only(['name', 'slug', 'timezone', 'phone', 'email']),
            'service' => $a->service?->only(['id', 'name', 'duration_minutes']),
            'staff' => $a->staff?->only(['id', 'name']),
        ];
    }
}
