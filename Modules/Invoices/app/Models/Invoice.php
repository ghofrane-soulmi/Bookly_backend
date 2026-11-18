<?php

namespace Modules\Invoices\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Clients\Models\Client;
use Modules\Tenant\Models\Concerns\BelongsToTenant;

/**
 * Money columns are integer minor units in `currency_code`. Totals are never
 * accepted from the client; InvoiceCalculator recomputes them.
 *
 * `status` is the stored workflow state (draft | sent). `state` is what the
 * UI shows, derived from it plus payments and the due date:
 * draft | sent | partial | paid | past_due.
 */
#[Fillable(['client_id', 'issued_on', 'due_on', 'discount_percent', 'tax_percent', 'notes'])]
class Invoice extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATES = ['draft', 'sent', 'partial', 'paid', 'past_due'];

    protected $appends = ['state', 'money'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'discount_percent' => 'float',
            'tax_percent' => 'float',
            'number' => 'integer',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function balance(): int
    {
        return max($this->total - $this->amount_paid, 0);
    }

    public function getStateAttribute(): string
    {
        if ($this->status === self::STATUS_DRAFT) {
            return 'draft';
        }

        if ($this->total > 0 && $this->amount_paid >= $this->total) {
            return 'paid';
        }

        if ($this->due_on && $this->due_on->lt(today())) {
            return 'past_due';
        }

        return $this->amount_paid > 0 ? 'partial' : 'sent';
    }

    /**
     * Same {amount, currency} shape the rest of the API uses for money.
     *
     * @return array<string, array{amount: int, currency: string}>
     */
    public function getMoneyAttribute(): array
    {
        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $this->currency_code];

        return [
            'subtotal' => $money((int) $this->subtotal),
            'discount' => $money((int) $this->discount_total),
            'tax' => $money((int) $this->tax_total),
            'total' => $money((int) $this->total),
            'paid' => $money((int) $this->amount_paid),
            'balance' => $money($this->balance()),
        ];
    }

    /**
     * Applies a SQL filter matching the derived `state`.
     */
    public function scopeInState($query, string $state)
    {
        $today = today()->toDateString();

        return match ($state) {
            'draft' => $query->where('status', self::STATUS_DRAFT),
            'paid' => $query->where('status', '!=', self::STATUS_DRAFT)->where('total', '>', 0)->whereColumn('amount_paid', '>=', 'total'),
            'past_due' => $query->where('status', '!=', self::STATUS_DRAFT)->whereColumn('amount_paid', '<', 'total')->where('due_on', '<', $today),
            'partial' => $query->where('status', '!=', self::STATUS_DRAFT)->where('amount_paid', '>', 0)->whereColumn('amount_paid', '<', 'total')->where('due_on', '>=', $today),
            'sent' => $query->where('status', '!=', self::STATUS_DRAFT)->where('amount_paid', 0)->where('total', '>', 0)->where('due_on', '>=', $today),
            default => $query,
        };
    }
}
