<?php

namespace Modules\Invoices\Support;

use App\Support\Money\MoneyInput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Appointments\Models\Appointment;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoicePayment;
use Modules\Tenant\Models\Business;

/**
 * All invoice writes go through here so numbering, totals and the paid
 * amount are always computed server-side, in exact integer minor units.
 * Callers must have the tenant context set to the invoice's business.
 */
class InvoiceService
{
    public const FIRST_NUMBER = 1001;

    /**
     * @param  array<string, mixed>  $attributes  client_id, issued_on, due_on, discount_percent, tax_percent, notes
     * @param  array<int, array<string, mixed>>  $items  description, quantity, unit_price (decimal string), service_id?, appointment_id?
     */
    public function create(Business $business, array $attributes, array $items): Invoice
    {
        return DB::transaction(function () use ($business, $attributes, $items) {
            $invoice = new Invoice($attributes);
            $invoice->currency_code = $business->currency_code;
            $invoice->status = Invoice::STATUS_DRAFT;
            $invoice->number = $this->nextNumber($business);
            $invoice->save();

            $this->replaceItems($invoice, $items);

            return $this->recalculate($invoice);
        });
    }

    public function update(Invoice $invoice, array $attributes, ?array $items): Invoice
    {
        return DB::transaction(function () use ($invoice, $attributes, $items) {
            $invoice->fill($attributes)->save();

            if ($items !== null) {
                $this->replaceItems($invoice, $items);
            }

            $invoice = $this->recalculate($invoice);

            // Throwing inside the transaction rolls the whole edit back.
            if ($invoice->total < $invoice->amount_paid) {
                throw new InvoiceException('The new total would be less than what has already been paid. Remove a payment first.');
            }

            return $invoice;
        });
    }

    public function duplicate(Business $business, Invoice $source): Invoice
    {
        $items = $source->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price_minor' => $item->unit_price,
            'service_id' => $item->service_id,
        ])->all();

        return $this->create($business, [
            'client_id' => $source->client_id,
            'issued_on' => today()->toDateString(),
            'due_on' => today()->addDays(14)->toDateString(),
            'discount_percent' => $source->discount_percent,
            'tax_percent' => $source->tax_percent,
            'notes' => $source->notes,
        ], $items);
    }

    /**
     * One draft invoice for a client from their completed, not-yet-invoiced
     * appointments: one line per appointment at its booked (snapshotted) price.
     *
     * @param  Collection<int, Appointment>  $appointments
     */
    public function fromAppointments(Business $business, Collection $appointments): Invoice
    {
        $items = $appointments->sortBy('starts_at')->values()->map(fn (Appointment $a) => [
            'description' => $a->service->name.' — '.$a->starts_at->setTimezone($business->timezone ?: 'UTC')->format('d M Y H:i').' with '.$a->staff->name,
            'quantity' => 1,
            'unit_price_minor' => $a->price?->getMinorAmount()->toInt() ?? 0,
            'service_id' => $a->service_id,
            'appointment_id' => $a->id,
        ])->all();

        return $this->create($business, [
            'client_id' => $appointments->first()->client_id,
            'issued_on' => today()->toDateString(),
            'due_on' => today()->addDays(14)->toDateString(),
            'discount_percent' => 0,
            'tax_percent' => 0,
        ], $items);
    }

    public function markSent(Invoice $invoice): Invoice
    {
        if ($invoice->status === Invoice::STATUS_DRAFT) {
            $invoice->status = Invoice::STATUS_SENT;
            $invoice->sent_at = now();
            $invoice->save();
        }

        return $invoice;
    }

    /**
     * Records a payment. Recording money against a draft also marks it sent
     * (you can't have been paid for an invoice you never issued).
     *
     * @throws InvoiceException when the amount exceeds the outstanding balance
     */
    public function addPayment(Invoice $invoice, int $amountMinor, string $method, string $paidOn, ?string $note): Invoice
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $method, $paidOn, $note) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($amountMinor <= 0) {
                throw new InvoiceException('The payment amount must be greater than zero.');
            }

            if ($amountMinor > $invoice->balance()) {
                throw new InvoiceException('The payment is larger than the outstanding balance.');
            }

            $invoice->payments()->create([
                'amount' => $amountMinor,
                'method' => $method,
                'paid_on' => $paidOn,
                'note' => $note,
            ]);

            $this->markSent($invoice);

            return $this->recalculate($invoice);
        });
    }

    public function deletePayment(Invoice $invoice, InvoicePayment $payment): Invoice
    {
        return DB::transaction(function () use ($invoice, $payment) {
            $payment->delete();

            return $this->recalculate($invoice);
        });
    }

    /**
     * Recomputes every stored total from the lines, percentages and payments.
     * Rounding: half-up per line, then on the discount and on the tax.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $subtotal = (int) $invoice->items()->sum('line_total');
        $discount = (int) round($subtotal * ((float) $invoice->discount_percent) / 100);
        $tax = (int) round(($subtotal - $discount) * ((float) $invoice->tax_percent) / 100);

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'total' => $subtotal - $discount + $tax,
            'amount_paid' => (int) $invoice->payments()->sum('amount'),
        ])->save();

        return $invoice->fresh(['client', 'items', 'payments']);
    }

    /**
     * Lines may arrive as a decimal `unit_price` (from the form, in major
     * units) or as an exact `unit_price_minor` (built server-side).
     */
    protected function replaceItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach (array_values($items) as $position => $item) {
            $unitPrice = array_key_exists('unit_price_minor', $item)
                ? (int) $item['unit_price_minor']
                : MoneyInput::fromDecimalString((string) $item['unit_price'], $invoice->currency_code)->getMinorAmount()->toInt();

            $quantity = (float) $item['quantity'];

            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => (int) round($unitPrice * $quantity),
                'service_id' => $item['service_id'] ?? null,
                'appointment_id' => $item['appointment_id'] ?? null,
                'position' => $position,
            ]);
        }
    }

    /**
     * Next per-business number. The business row is locked so two invoices
     * created at the same moment can't get the same number.
     */
    protected function nextNumber(Business $business): int
    {
        Business::whereKey($business->id)->lockForUpdate()->first();

        $max = Invoice::withTrashed()->max('number');

        return $max ? $max + 1 : self::FIRST_NUMBER;
    }
}
