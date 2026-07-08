<?php

namespace Modules\Tenant\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Tenant\Support\Tenant;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($businessId = app(Tenant::class)->id()) {
            $builder->where($model->qualifyColumn('business_id'), $businessId);
        }
    }
}
