<?php

namespace App\Support\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class MissingTenantException extends RuntimeException
{
    public static function forModel(Model $model): self
    {
        return new self(sprintf('Cannot persist %s without a tenant in context.', $model::class));
    }
}
