<?php

namespace App\Domain\Audit\Builders;

use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent builder for audit tables: refuses mass update/delete so no application path can
 * rewrite history without going through the model events, which refuse as well.
 *
 * @extends Builder<Model>
 */
class ImmutableBuilder extends Builder
{
    public function update(array $values): int
    {
        throw ImmutableAuditRecordException::because('update');
    }

    public function delete(): mixed
    {
        throw ImmutableAuditRecordException::because('delete');
    }

    public function forceDelete(): mixed
    {
        throw ImmutableAuditRecordException::because('delete');
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw ImmutableAuditRecordException::because('update');
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw ImmutableAuditRecordException::because('update');
    }
}
