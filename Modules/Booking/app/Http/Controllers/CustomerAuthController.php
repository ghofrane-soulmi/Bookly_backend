<?php

namespace Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\DemoAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Auth\Mail\PasswordResetMail;
use Modules\Booking\Http\Controllers\Concerns\RespondsWithEnvelope;
use Modules\Booking\Models\Customer;

/**
 * Front-office customer accounts. Mirrors the staff AuthController but uses
 * the `customer` guard and the platform-wide `customers` table.
 */
class CustomerAuthController extends Controller
{
    use RespondsWithEnvelope;

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('customers', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $customer = Customer::create($validator->validated());
        $token = auth('customer')->login($customer);

        return $this->envelope(['customer' => $customer, ...$this->tokenPayload($token)], 'Account created', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        if (! $token = auth('customer')->attempt($validator->validated())) {
            return $this->envelope(null, 'Invalid email or password', 401);
        }

        return $this->envelope(['customer' => auth('customer')->user(), ...$this->tokenPayload($token)], 'Logged in');
    }

    public function me(): JsonResponse
    {
        return $this->envelope(auth('customer')->user(), 'Profile retrieved');
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
            'current_password' => ['required_with:password', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();

        if (DemoAccounts::changesLogin($customer->email, $data)) {
            return $this->envelope(null, DemoAccounts::MESSAGE, 403);
        }

        if (! empty($data['password']) && ! Hash::check($data['current_password'] ?? '', $customer->password)) {
            return $this->validationError(['current_password' => ['The current password is incorrect.']]);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        unset($data['current_password'], $data['password_confirmation']);

        DB::transaction(function () use ($customer, $data) {
            $customer->update($data);

            // Keep each business's copy of the contact details in sync, so staff
            // see the customer's current name/email/phone in the back office.
            $customer->clients()->update(array_intersect_key($data, array_flip(['name', 'email', 'phone'])) + ['updated_at' => now()]);
        });

        return $this->envelope($customer->fresh(), 'Profile updated');
    }

    public function logout(): JsonResponse
    {
        auth('customer')->logout();

        return $this->envelope(null, 'Logged out');
    }

    public function refresh(): JsonResponse
    {
        return $this->envelope($this->tokenPayload(auth('customer')->refresh()), 'Token refreshed');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $email = $validator->validated()['email'];

        // Same response whether or not the account exists (no enumeration).
        if (Customer::where('email', $email)->exists()) {
            $token = Str::random(64);

            DB::table('customer_password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $resetUrl = rtrim(config('booking.front_office_url'), '/')
                .'/reset-password?token='.$token.'&email='.urlencode($email);

            Mail::to($email)->send(new PasswordResetMail($resetUrl));
        }

        return $this->envelope(null, 'If that email is registered, a reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();

        if (DemoAccounts::isDemo($data['email'])) {
            return $this->envelope(null, DemoAccounts::MESSAGE, 403);
        }

        $record = DB::table('customer_password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return $this->envelope(null, 'This password reset link is invalid.', 422);
        }

        if (now()->diffInMinutes($record->created_at, absolute: true) > 60) {
            return $this->envelope(null, 'This password reset link has expired.', 422);
        }

        $customer = Customer::where('email', $data['email'])->first();

        if (! $customer) {
            return $this->envelope(null, 'This password reset link is invalid.', 422);
        }

        $customer->forceFill(['password' => $data['password']])->save();
        DB::table('customer_password_reset_tokens')->where('email', $data['email'])->delete();

        return $this->envelope(null, 'Password reset successfully. You can now log in.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function tokenPayload(string $token): array
    {
        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('customer')->factory()->getTTL() * 60,
        ];
    }
}
