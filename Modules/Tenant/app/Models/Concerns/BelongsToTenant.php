<?php

namespace Modules\Tenant\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Models\Scopes\TenantScope;
use Modules\Tenant\Support\Tenant;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if (is_null($model->business_id)) {
                $model->business_id = app(Tenant::class)->id();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
