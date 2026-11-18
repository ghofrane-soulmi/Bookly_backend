<?php

namespace Modules\Invoices\Http\Controllers;

use App\Http\Controllers\Concerns\ReturnsServerErrorResponse;
use App\Support\Money\InvalidMoneyAmountException;
use App\Support\Money\MoneyInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Appointments\Models\Appointment;
use Modules\Invoices\Mail\InvoiceMail;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoicePayment;
use Modules\Invoices\Support\InvoiceException;
use Modules\Invoices\Support\InvoiceService;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

/**
 * Same response envelope as the other back-office modules:
 * { data, status: { message, code } } (+ meta / errors).
 */
class InvoiceController extends Controller
{
    use ReturnsServerErrorResponse;

    public function __construct(protected InvoiceService $invoices) {}

    public function handleListInvoices(Request $request): JsonResponse
    {
        try {
            $query = Invoice::with('client')
                ->when($request->filled('state'), fn ($q) => $q->inState((string) $request->query('state')))
                ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->query('client_id')))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = (string) $request->query('search');
                    $q->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%")));
                })
                ->orderByDesc('issued_on')
                ->orderByDesc('number');

            $paginator = $query->paginate(min((int) $request->query('per_page', 10), 100));

            return response()->json([
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'summary' => $this->summary(),
                ],
                'status' => ['message' => 'Invoices retrieved successfully', 'code' => 200],
            ]);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function handleGetInvoice(int $id): JsonResponse
    {
        $invoice = Invoice::with(['client', 'items', 'payments'])->find($id);

        if (! $invoice) {
            return $this->notFound();
        }

        return $this->ok($invoice, 'Invoice retrieved successfully');
    }

    public function handleCreateInvoice(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), $this->rules());

            if ($validator->fails()) {
                return $this->validationError($validator->errors()->toArray());
            }

            $data = $validator->validated();
            $invoice = $this->invoices->create($this->business(), $this->attributes($data), $data['items']);

            return $this->ok($invoice, 'Invoice created successfully', 201);
        } catch (InvalidMoneyAmountException $e) {
            return $this->validationError(['items' => [$e->getMessage()]]);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function handleUpdateInvoice(Request $request, int $id): JsonResponse
    {
        try {
            $invoice = Invoice::find($id);

            if (! $invoice) {
                return $this->notFound();
            }

            $validator = Validator::make($request->all(), $this->rules(partial: true));

            if ($validator->fails()) {
                return $this->validationError($validator->errors()->toArray());
            }

            $data = $validator->validated();
            $invoice = $this->invoices->update($invoice, $this->attributes($data), $data['items'] ?? null);

            return $this->ok($invoice, 'Invoice updated successfully');
        } catch (InvoiceException $e) {
            return $this->unprocessable($e->getMessage());
        } catch (InvalidMoneyAmountException $e) {
            return $this->validationError(['items' => [$e->getMessage()]]);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function handleDeleteInvoice(int $id): JsonResponse
    {
        $invoice = Invoice::find($id);

        if (! $invoice) {
            return $this->notFound();
        }

        // Keep an audit trail: money was received against it.
        if ($invoice->amount_paid > 0) {
            return $this->unprocessable('This invoice has payments recorded and cannot be deleted. Remove the payments first.');
        }

        $invoice->delete();

        return response()->json(['status' => ['message' => 'Invoice deleted successfully', 'code' => 200]]);
    }

    public function handleDuplicateInvoice(int $id): JsonResponse
    {
        try {
            $invoice = Invoice::with('items')->find($id);

            if (! $invoice) {
                return $this->notFound();
            }

            return $this->ok($this->invoices->duplicate($this->business(), $invoice), 'Invoice duplicated', 201);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * Marks the invoice sent and emails it to the client when they have an
     * address. A mail failure doesn't undo the "sent" state; it's reported.
     */
    public function handleSendInvoice(int $id): JsonResponse
    {
        $invoice = Invoice::with(['client', 'items'])->find($id);

        if (! $invoice) {
            return $this->notFound();
        }

        $invoice = $this->invoices->markSent($invoice);
        $emailed = false;

        if ($invoice->client?->email) {
            try {
                Mail::to($invoice->client->email)->send(new InvoiceMail($invoice, $this->business()));
                $emailed = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $message = $emailed
            ? "Invoice sent to {$invoice->client->email}"
            : ($invoice->client?->email ? 'Invoice marked as sent, but the email could not be delivered' : 'Invoice marked as sent (client has no email address)');

        return $this->ok($invoice->fresh(['client', 'items', 'payments']), $message);
    }

    public function handleAddPayment(Request $request, int $id): JsonResponse
    {
        try {
            $invoice = Invoice::find($id);

            if (! $invoice) {
                return $this->notFound();
            }

            $validator = Validator::make($request->all(), [
                'amount' => ['required', 'numeric', 'gt:0'],
                'method' => ['required', Rule::in(InvoicePayment::METHODS)],
                'paid_on' => ['required', 'date_format:Y-m-d'],
                'note' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                return $this->validationError($validator->errors()->toArray());
            }

            $data = $validator->validated();
            $amount = MoneyInput::fromDecimalString((string) $data['amount'], $invoice->currency_code)->getMinorAmount()->toInt();

            $invoice = $this->invoices->addPayment($invoice, $amount, $data['method'], $data['paid_on'], $data['note'] ?? null);

            return $this->ok($invoice, 'Payment recorded', 201);
        } catch (InvoiceException $e) {
            return $this->validationError(['amount' => [$e->getMessage()]]);
        } catch (InvalidMoneyAmountException $e) {
            return $this->validationError(['amount' => [$e->getMessage()]]);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function handleDeletePayment(int $id, int $paymentId): JsonResponse
    {
        $invoice = Invoice::find($id);
        $payment = $invoice?->payments()->find($paymentId);

        if (! $invoice || ! $payment) {
            return $this->notFound();
        }

        return $this->ok($this->invoices->deletePayment($invoice, $payment), 'Payment removed');
    }

    /**
     * Completed appointments not yet on a (non-deleted) invoice, so the UI can
     * offer "invoice these". Optionally for one client.
     */
    public function handleUninvoicedAppointments(Request $request): JsonResponse
    {
        $appointments = $this->uninvoicedQuery()
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->query('client_id')))
            ->with(['client', 'service', 'staff'])
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get();

        return $this->ok($appointments, 'Uninvoiced appointments retrieved');
    }

    public function handleCreateFromAppointments(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'appointment_ids' => ['required', 'array', 'min:1', 'max:50'],
                'appointment_ids.*' => ['integer', 'distinct'],
            ]);

            if ($validator->fails()) {
                return $this->validationError($validator->errors()->toArray());
            }

            $ids = $validator->validated()['appointment_ids'];
            $appointments = $this->uninvoicedQuery()->whereKey($ids)->with(['service', 'staff'])->get();

            if ($appointments->count() !== count($ids)) {
                return $this->validationError(['appointment_ids' => ['Only completed appointments that are not already invoiced can be invoiced.']]);
            }

            if ($appointments->pluck('client_id')->unique()->count() > 1) {
                return $this->validationError(['appointment_ids' => ['All appointments on one invoice must be for the same client.']]);
            }

            $invoice = $this->invoices->fromAppointments($this->business(), $appointments);

            return $this->ok($invoice, 'Invoice created from appointments', 201);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    protected function uninvoicedQuery()
    {
        return Appointment::where('status', Appointment::STATUS_COMPLETED)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->whereColumn('invoice_items.appointment_id', 'appointments.id')
                ->whereNull('invoices.deleted_at'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(): array
    {
        $counts = [];
        foreach (Invoice::STATES as $state) {
            $counts[$state] = Invoice::query()->inState($state)->count();
        }

        $issued = Invoice::where('status', '!=', Invoice::STATUS_DRAFT);
        $currency = $this->business()->currency_code;
        $money = fn ($amount) => ['amount' => (int) $amount, 'currency' => $currency];

        return [
            'counts' => $counts,
            'invoiced' => $money((clone $issued)->sum('total')),
            'paid' => $money((clone $issued)->sum('amount_paid')),
            'outstanding' => $money((clone $issued)->selectRaw('COALESCE(SUM(CASE WHEN total > amount_paid THEN total - amount_paid ELSE 0 END), 0) as v')->value('v')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(bool $partial = false): array
    {
        $req = $partial ? ['sometimes', 'required'] : ['required'];
        $businessId = app(Tenant::class)->id();

        return [
            'client_id' => [...$req, 'integer', Rule::exists('clients', 'id')->where('business_id', $businessId)->whereNull('deleted_at')],
            'issued_on' => [...$req, 'date_format:Y-m-d'],
            'due_on' => [...$req, 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => [...$req, 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('business_id', $businessId)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['client_id', 'issued_on', 'due_on', 'discount_percent', 'tax_percent', 'notes']));
    }

    protected function business(): Business
    {
        return Business::findOrFail(app(Tenant::class)->id());
    }

    protected function ok(mixed $data, string $message, int $code = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'status' => ['message' => $message, 'code' => $code]]);
    }

    protected function notFound(): JsonResponse
    {
        return response()->json(['status' => ['message' => 'Invoice not found', 'code' => 404]]);
    }

    protected function unprocessable(string $message): JsonResponse
    {
        return response()->json(['status' => ['message' => $message, 'code' => 422]]);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    protected function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => ['message' => 'Validation failed', 'code' => 422], 'errors' => $errors]);
    }
}
