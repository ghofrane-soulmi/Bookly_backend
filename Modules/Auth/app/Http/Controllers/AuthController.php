<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Requests\RegisterRequest;
use Modules\Auth\Models\User;
use Modules\Tenant\Models\Business;

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

            $user = User::create([
                'business_id' => $business->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_OWNER,
            ]);

            return [$user, $business];
        });

        $token = auth('api')->login($user);

        return response()->json([
            'user' => $user,
            'business' => $business,
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

        return response()->json([
            'user' => $user,
            'business' => $user->business,
            ...$this->tokenResponse($token),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'business' => $request->user()->business,
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
