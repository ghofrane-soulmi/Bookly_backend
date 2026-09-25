<?php

namespace Modules\Tenant\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Tenant\Support\NoTenantContextException;
use Modules\Tenant\Support\Tenant;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $businessId = app(Tenant::class)->id();

        if (! $businessId) {
            throw new NoTenantContextException(
                'Attempted to query '.$model::class.' with no tenant context set. '
                .'If this is intentional platform/console code, bypass explicitly with '
                .'->withoutGlobalScope('.self::class.'::class).',
            );
        }

        $builder->where($model->qualifyColumn('business_id'), $businessId);
    }
}
