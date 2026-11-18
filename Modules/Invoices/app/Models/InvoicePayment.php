<?php

namespace Modules\Invoices\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not tenant-scoped itself: only ever reached through its (scoped) Invoice.
 */
#[Fillable(['amount', 'method', 'paid_on', 'note'])]
class InvoicePayment extends Model
{
    public const METHODS = ['cash', 'card', 'bank_transfer', 'other'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date:Y-m-d',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
