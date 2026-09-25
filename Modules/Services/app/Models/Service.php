<?php

namespace Modules\Services\Models;

use App\Casts\MoneyCast;
use App\Casts\SerializesMoneyAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Tenant\Models\Concerns\BelongsToTenant;
use Modules\Tenant\Models\Concerns\HasBusinessCurrency;

#[Fillable(['name', 'description', 'category', 'duration_minutes', 'price', 'currency_code', 'color', 'is_active'])]
#[Hidden(['currency_code'])]
class Service extends Model
{
    use BelongsToTenant, HasBusinessCurrency, SerializesMoneyAttributes, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function moneyAttributes(): array
    {
        return ['price'];
    }
}
