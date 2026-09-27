<?php

namespace App\Support\Tenancy;

use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\Exceptions\MissingTenantException;
use App\Support\Tenancy\Exceptions\TenantMismatchException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->id());
            }

            if ($model->getAttribute('tenant_id') === null) {
                throw MissingTenantException::forModel($model);
            }

            if ($context->has() && ! $context->isBypassed() && (int) $model->getAttribute('tenant_id') !== $context->id()) {
                throw TenantMismatchException::forModel($model, $context->id());
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw TenantMismatchException::forModel($model, app(TenantContext::class)->id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
