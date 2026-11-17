<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Appointments\Support\AppointmentScheduler;
use Modules\Auth\Models\User;
use Modules\Booking\Http\Controllers\Concerns\RespondsWithEnvelope;
use Modules\Booking\Support\ResolvesPublicBusiness;
use Modules\Services\Models\Service;

/**
 * Unauthenticated, read-only endpoints behind a business's public page
 * (/b/{slug} in the front office). Only exposes what a customer needs to book.
 */
class PublicBusinessController extends Controller
{
    use ResolvesPublicBusiness, RespondsWithEnvelope;

    public function __construct(protected AppointmentScheduler $scheduler) {}

    public function show(string $slug): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

        if (! $business) {
            return $this->envelope(null, 'Business not found', 404);
        }

        $services = Service::where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'category', 'duration_minutes', 'price', 'currency_code', 'color']);

        return $this->envelope([
            'business' => $business->only([
                'name', 'slug', 'email', 'phone', 'timezone', 'currency_code', 'locale', 'operating_hours',
            ]),
            'services' => $services,
            'staff' => User::orderBy('name')->get(['id', 'name'])->map->only(['id', 'name']),
            'booking' => [
                'max_days_ahead' => config('booking.max_days_ahead'),
                'cancel_cutoff_hours' => config('booking.cancel_cutoff_hours'),
            ],
        ], 'Business retrieved');
    }

    public function slots(Request $request, string $slug): JsonResponse
    {
        $business = $this->resolveBusiness($slug);

        if (! $business) {
            return $this->envelope(null, 'Business not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $service = Service::where('is_active', true)->find($data['service_id']);

        if (! $service) {
            return $this->envelope(null, 'Service not found', 404);
        }

        $today = CarbonImmutable::now($business->timezone ?: 'UTC')->startOfDay();
        $day = CarbonImmutable::parse($data['date'], $business->timezone ?: 'UTC');

        if ($day->lt($today) || $day->gt($today->addDays(config('booking.max_days_ahead')))) {
            return $this->envelope(['date' => $data['date'], 'slots' => []], 'Date is outside the booking window');
        }

        $staff = User::query()
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get();

        return $this->envelope([
            'date' => $data['date'],
            'slots' => $this->scheduler->availableSlots($business, $service, $data['date'], $staff),
        ], 'Slots retrieved');
    }
}
