<?php

namespace Modules\Invoices\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not tenant-scoped itself: only ever reached through its (scoped) Invoice.
 */
#[Fillable(['appointment_id', 'service_id', 'description', 'quantity', 'unit_price', 'line_total', 'position'])]
class InvoiceItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'position' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
