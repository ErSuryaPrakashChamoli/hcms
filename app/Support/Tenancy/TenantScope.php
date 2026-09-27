<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');

        if ($context->has()) {
            $builder->where($column, $context->id());

            return;
        }

        // Fail closed: without a bound tenant, tenant-owned data is invisible.
        $builder->whereRaw('1 = 0');
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenancy', fn (Builder $builder) => $builder->withoutGlobalScope($this));
    }
}
