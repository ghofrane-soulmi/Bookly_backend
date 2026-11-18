<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Requests\RegisterRequest;
use Modules\Auth\Mail\PasswordResetMail;
use Modules\Auth\Models\User;
use Modules\Auth\Support\DefaultRoles;
use Modules\Tenant\Models\Business;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $business] = DB::transaction(function () use ($data) {
            $business = Business::create([
                'name' => $data['business_name'],
                'slug' => Business::uniqueSlugFrom($data['business_name']),
                'timezone' => $data['timezone'] ?? 'UTC',
            ]);

            // business_id can't be mass-assigned (not Fillable, to stop a tenant-
            // scoped request body from ever reassigning a user's tenant) and
            // BelongsToTenant's auto-populate hook can't help either since no
            // tenant context exists yet during registration — set it directly.
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->business_id = $business->id;
            $user->save();

            DefaultRoles::seed($business);

            // DefaultRoles::seed() already points the permission "team" context
            // at this business, so assignRole() resolves against the role it
            // just created rather than a stale/no team context.
            app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);
            $user->assignRole(User::ROLE_OWNER);

            return [$user, $business];
        });

        $token = auth('api')->login($user);

        return response()->json([
            'user' => $user,
            'business' => $business,
            'permissions' => $this->permissionNames($user),
            ...$this->tokenResponse($token),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = auth('api')->user();

        // login is a public route (no 'tenant' middleware), so nothing else
        // has pointed the permission "team" context at this user's business
        // yet — without this, the serialized $user's role/roles would be
        // empty even though the assignment exists.
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->business_id);

        return response()->json([
            'user' => $user,
            'business' => $user->business,
            'permissions' => $this->permissionNames($user),
            ...$this->tokenResponse($token),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'business' => $request->user()->business,
            'permissions' => $this->permissionNames($request->user()),
        ]);
    }

    /**
     * Self-service profile edit for any signed-in user, independent of the
     * staff.manage permission (which plain staff members don't have).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'current_password' => ['required_with:password', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => ['message' => 'Validation failed', 'code' => 422],
                'errors' => $validator->errors(),
            ]);
        }

        $data = $validator->validated();

        if (! empty($data['password']) && ! Hash::check($data['current_password'] ?? '', $user->password)) {
            return response()->json([
                'status' => ['message' => 'Validation failed', 'code' => 422],
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ]);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        unset($data['current_password']);

        $user->update($data);

        return response()->json([
            'data' => $user->fresh(),
            'status' => ['message' => 'Profile updated successfully', 'code' => 200],
        ]);
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function refresh(): JsonResponse
    {
        return response()->json($this->tokenResponse(auth('api')->refresh()));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => ['message' => 'Validation failed', 'code' => 422],
                'errors' => $validator->errors(),
            ]);
        }

        $email = $validator->validated()['email'];
        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        // Always respond the same way whether or not the email exists, so this
        // endpoint can't be used to enumerate registered accounts.
        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $resetUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/')
                .'/reset-password?token='.$token.'&email='.urlencode($email);

            Mail::to($email)->send(new PasswordResetMail($resetUrl));
        }

        return response()->json([
            'status' => ['message' => 'If that email is registered, a reset link has been sent.', 'code' => 200],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => ['message' => 'Validation failed', 'code' => 422],
                'errors' => $validator->errors(),
            ]);
        }

        $data = $validator->validated();

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return response()->json([
                'status' => ['message' => 'This password reset link is invalid.', 'code' => 422],
            ]);
        }

        if (now()->diffInMinutes($record->created_at, absolute: true) > 60) {
            return response()->json([
                'status' => ['message' => 'This password reset link has expired.', 'code' => 422],
            ]);
        }

        $user = User::withoutGlobalScopes()->where('email', $data['email'])->first();

        if (! $user) {
            return response()->json([
                'status' => ['message' => 'This password reset link is invalid.', 'code' => 422],
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return response()->json([
            'status' => ['message' => 'Password reset successfully. You can now log in.', 'code' => 200],
        ]);
    }

    /**
     * Permission names the user holds through their role(s), so the UI can
     * show only what the API will actually allow. The API still enforces
     * every permission itself; this is for presentation only.
     *
     * @return array<int, string>
     */
    protected function permissionNames(User $user): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->business_id);

        return $user->getAllPermissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function tokenResponse(string $token): array
    {
        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ];
    }
}
