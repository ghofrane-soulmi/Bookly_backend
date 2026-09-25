<?php

namespace Modules\Clients\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Appointments\Models\Appointment;
use Modules\Clients\Models\Client;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

class ClientController extends Controller
{
    use ReturnsServerErrorResponse;

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
            return $this->serverError($e);
        }
    }

    public function handleGetClient($id)
    {
        try {
            $client = Client::with(['appointments' => function ($query) {
                $query->with(['service', 'staff'])->orderByDesc('starts_at');
            }])->find($id);

            if (! $client) {
                return response()->json([
                    'status' => ['message' => 'Client not found', 'code' => 404],
                ]);
            }

            // Uses each appointment's own snapshotted price (what was actually
            // charged), not the service's current price — see Phase 0 Step 5.
            $totalSpentMinor = $client->appointments
                ->where('status', Appointment::STATUS_COMPLETED)
                ->sum(fn ($appointment) => $appointment->price->getMinorAmount()->toInt());

            $currencyCode = Business::find(app(Tenant::class)->id())->currency_code;

            $clientData = $client->toArray();
            $clientData['stats'] = [
                'total_appointments' => $client->appointments->count(),
                'completed_appointments' => $client->appointments->where('status', Appointment::STATUS_COMPLETED)->count(),
                'total_spent' => ['amount' => $totalSpentMinor, 'currency' => $currencyCode],
            ];

            return response()->json([
                'data' => $clientData,
                'status' => ['message' => 'Client retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
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
            return $this->serverError($e);
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
            return $this->serverError($e);
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
            return $this->serverError($e);
        }
    }
}
