<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;

class StaffController extends Controller
{
    use ReturnsServerErrorResponse;

    public function handleListStaff(Request $request)
    {
        try {
            $query = User::query()->orderBy('name');

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->filled('role')) {
                $query->where('role', $request->query('role'));
            }

            if (! $request->filled('page')) {
                return response()->json([
                    'data' => $query->get(['id', 'name', 'email', 'role']),
                    'status' => ['message' => 'Staff retrieved successfully', 'code' => 200],
                ]);
            }

            $paginator = $query->paginate((int) $request->query('per_page', 10), ['id', 'name', 'email', 'role', 'created_at']);

            return response()->json([
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'status' => ['message' => 'Staff retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleGetStaff($id)
    {
        try {
            $staff = User::find($id);

            if (! $staff) {
                return response()->json([
                    'status' => ['message' => 'Staff member not found', 'code' => 404],
                ]);
            }

            return response()->json([
                'data' => $staff,
                'status' => ['message' => 'Staff member retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleCreateStaff(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
                'password' => ['required', 'string', 'min:8'],
                'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF])],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $staff = User::create($validator->validated());

            return response()->json([
                'data' => $staff,
                'status' => ['message' => 'Staff member created successfully', 'code' => 201],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleUpdateStaff(Request $request, $id)
    {
        try {
            $staff = User::find($id);

            if (! $staff) {
                return response()->json([
                    'status' => ['message' => 'Staff member not found', 'code' => 404],
                ]);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff->id)->whereNull('deleted_at')],
                'password' => ['sometimes', 'nullable', 'string', 'min:8'],
                'role' => ['sometimes', 'required', Rule::in([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF])],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $data = $validator->validated();

            if (empty($data['password'])) {
                unset($data['password']);
            }

            $staff->update($data);

            return response()->json([
                'data' => $staff->fresh(),
                'status' => ['message' => 'Staff member updated successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleDeleteStaff($id)
    {
        try {
            $staff = User::find($id);

            if (! $staff) {
                return response()->json([
                    'status' => ['message' => 'Staff member not found', 'code' => 404],
                ]);
            }

            if ($staff->id === Auth::id()) {
                return response()->json([
                    'status' => ['message' => 'You cannot delete your own account.', 'code' => 422],
                ]);
            }

            if (User::count() <= 1) {
                return response()->json([
                    'status' => ['message' => 'Cannot delete the last remaining staff member.', 'code' => 422],
                ]);
            }

            $staff->delete();

            return response()->json([
                'status' => ['message' => 'Staff member deleted successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }
}
