<?php

namespace Modules\Services\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use App\Support\Money\InvalidMoneyAmountException;
use App\Support\Money\MoneyInput;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

class ServiceController extends Controller
{
    use ReturnsServerErrorResponse;

    public function handleListServices(Request $request)
    {
        try {
            $query = Service::query()->orderBy('name');

            if ($request->filled('search')) {
                $query->where('name', 'like', '%'.$request->query('search').'%');
            }

            if ($request->filled('status')) {
                $query->where('is_active', $request->query('status') === 'active');
            }

            if ($request->filled('category')) {
                $query->where('category', $request->query('category'));
            }

            $categories = Service::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');

            if (! $request->filled('page')) {
                return response()->json([
                    'data' => $query->get(),
                    'status' => ['message' => 'Services retrieved successfully', 'code' => 200],
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
                    'categories' => $categories,
                ],
                'status' => ['message' => 'Services retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleGetService($id)
    {
        try {
            $service = Service::find($id);

            if (! $service) {
                return response()->json([
                    'status' => ['message' => 'Service not found', 'code' => 404],
                ]);
            }

            return response()->json([
                'data' => $service,
                'status' => ['message' => 'Service retrieved successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleCreateService(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'category' => ['nullable', 'string', 'max:255'],
                'duration_minutes' => ['required', 'integer', 'min:1'],
                'price' => ['required', 'numeric', 'min:0'],
                'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $data = $validator->validated();

            try {
                $data['price'] = MoneyInput::fromDecimalString((string) $data['price'], $this->currencyCode());
            } catch (InvalidMoneyAmountException $e) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => ['price' => [$e->getMessage()]],
                ]);
            }

            $service = Service::create($data);

            return response()->json([
                'data' => $service,
                'status' => ['message' => 'Service created successfully', 'code' => 201],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleUpdateService(Request $request, $id)
    {
        try {
            $service = Service::find($id);

            if (! $service) {
                return response()->json([
                    'status' => ['message' => 'Service not found', 'code' => 404],
                ]);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'category' => ['nullable', 'string', 'max:255'],
                'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1'],
                'price' => ['sometimes', 'required', 'numeric', 'min:0'],
                'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'is_active' => ['sometimes', 'boolean'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => ['message' => 'Validation failed', 'code' => 422],
                    'errors' => $validator->errors(),
                ]);
            }

            $data = $validator->validated();

            if (isset($data['price'])) {
                try {
                    $data['price'] = MoneyInput::fromDecimalString((string) $data['price'], $this->currencyCode());
                } catch (InvalidMoneyAmountException $e) {
                    return response()->json([
                        'status' => ['message' => 'Validation failed', 'code' => 422],
                        'errors' => ['price' => [$e->getMessage()]],
                    ]);
                }
            }

            $service->update($data);

            return response()->json([
                'data' => $service->fresh(),
                'status' => ['message' => 'Service updated successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    public function handleDeleteService($id)
    {
        try {
            $service = Service::find($id);

            if (! $service) {
                return response()->json([
                    'status' => ['message' => 'Service not found', 'code' => 404],
                ]);
            }

            $service->delete();

            return response()->json([
                'status' => ['message' => 'Service deleted successfully', 'code' => 200],
            ]);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    protected function currencyCode(): string
    {
        return Business::find(app(Tenant::class)->id())->currency_code;
    }
}
