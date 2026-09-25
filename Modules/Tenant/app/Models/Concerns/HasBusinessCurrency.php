<?php

namespace Modules\Tenant\Models\Concerns;

use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

/**
 * Auto-populates a model's currency_code from its business at creation time,
 * mirroring how BelongsToTenant auto-populates business_id. Models using this
 * still accept an explicit currency_code if one is set before saving.
 */
trait HasBusinessCurrency
{
    public static function bootHasBusinessCurrency(): void
    {
        static::creating(function ($model): void {
            if (is_null($model->currency_code)) {
                $businessId = app(Tenant::class)->id();

                if ($businessId) {
                    $model->currency_code = Business::find($businessId)?->currency_code;
                }
            }
        });
    }
}
