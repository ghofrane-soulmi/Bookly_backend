<?php

namespace Modules\Tenant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

class BusinessController extends Controller
{
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
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
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
                'currency' => ['sometimes', 'required', 'string', 'size:3'],
                'notify_confirmation_email' => ['sometimes', 'boolean'],
                'notify_reminder_email' => ['sometimes', 'boolean'],
                'reminder_lead_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
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
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
