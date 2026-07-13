<?php

namespace Modules\Services\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenant\Models\Concerns\BelongsToTenant;

#[Fillable(['name', 'description', 'category', 'duration_minutes', 'price', 'color', 'is_active'])]
class Service extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
