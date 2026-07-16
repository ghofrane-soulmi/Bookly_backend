<?php

namespace Modules\Clients\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Clients\Models\Client;

class ClientController extends Controller
{
    public function handleListClients(Request $request)
    {
        try {
            $query = Client::query()->orderBy('name');

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            if (! $request->filled('page')) {
                return response()->json([
                    'data' => $query->get(),
                    'status' => ['message' => 'Clients retrieved successfully', 'code' => 200],
                ]);
            }

            $paginator = $query->paginate((int) $request->query('per_page', 10));

            return response()->json([
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'status' => ['message' => 'Clients retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleGetClient($id)
    {
        try {
            $client = Client::find($id);

            if (! $client) {
                return response()->json([
                    'status' => ['message' => 'Client not found', 'code' => 404],
                ]);
            }

            return response()->json([
                'data' => $client,
                'status' => ['message' => 'Client retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleCreateClient(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'notes' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $client = Client::create($validator->validated());

            return response()->json([
                'data' => $client,
                'status' => ['message' => 'Client created successfully', 'code' => 201],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleUpdateClient(Request $request, $id)
    {
        try {
            $client = Client::find($id);

            if (! $client) {
                return response()->json([
                    'status' => ['message' => 'Client not found', 'code' => 404],
                ]);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'notes' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $client->update($validator->validated());

            return response()->json([
                'data' => $client->fresh(),
                'status' => ['message' => 'Client updated successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleDeleteClient($id)
    {
        try {
            $client = Client::find($id);

            if (! $client) {
                return response()->json([
                    'status' => ['message' => 'Client not found', 'code' => 404],
                ]);
            }

            $client->delete();

            return response()->json([
                'status' => ['message' => 'Client deleted successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => ['message' => 'Server error', 'code' => 500],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
