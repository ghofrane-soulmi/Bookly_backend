<?php

namespace Modules\Tenant\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

class BusinessController extends Controller
{
    use ReturnsServerErrorResponse;

    public function handleGetBusiness()
    {
        try {
            $business = Business::find(app(Tenant::class)->id());

            if (! $business) {
                return response()->json([
                    'status' => ['message' => 'Business not found', 'code' => 404],
                ]);
            }

            return response()->json([
                'data' => $business,
                'status' => ['message' => 'Business retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleUpdateBusiness(Request $request)
    {
        try {
            $business = Business::find(app(Tenant::class)->id());

            if (! $business) {
                return response()->json([
                    'status' => ['message' => 'Business not found', 'code' => 404],
                ]);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'timezone' => ['sometimes', 'required', 'string', 'max:255', 'timezone'],
                'currency_code' => [
                    'sometimes', 'required', 'string', 'size:3',
                    Rule::exists('currencies', 'code')->where('is_active', true),
                ],
                'notify_confirmation_email' => ['sometimes', 'boolean'],
                'notify_reminder_email' => ['sometimes', 'boolean'],
                'reminder_lead_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
                'operating_hours' => ['sometimes', 'nullable', 'array'],
                ...$this->operatingHoursRules(),
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $business->update($validator->validated());

            return response()->json([
                'data' => $business->fresh(),
                'status' => ['message' => 'Business updated successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    /**
     * Per-day rules for the operating_hours array: each day is either absent/null
     * (closed) or an {open, close} pair of "H:i" times with close after open.
     *
     * @return array<string, mixed>
     */
    protected function operatingHoursRules(): array
    {
        $rules = [];

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $rules["operating_hours.{$day}"] = ['nullable', 'array'];
            $rules["operating_hours.{$day}.open"] = ['required_with:operating_hours.'.$day, 'date_format:H:i'];
            $rules["operating_hours.{$day}.close"] = ['required_with:operating_hours.'.$day, 'date_format:H:i', 'after:operating_hours.'.$day.'.open'];
        }

        return $rules;
    }
}
