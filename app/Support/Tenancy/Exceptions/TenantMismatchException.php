<?php

namespace App\Support\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class TenantMismatchException extends RuntimeException
{
    public static function forModel(Model $model, ?int $contextTenantId): self
    {
        return new self(sprintf(
            '%s belongs to tenant %s but the current context is tenant %s. Cross-tenant writes are forbidden.',
            $model::class,
            $model->getAttribute('tenant_id') ?? 'null',
            $contextTenantId ?? 'null',
        ));
    }
}
