<?php

namespace App\Domain\Compliance\Concerns;

use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Identity\Scopes\AccessScope;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Part Q: rows that belong to a statutory return can change only while the return is editable
 * (before approval). After that they are frozen; corrections go through a revision.
 *
 * @mixin Model
 */
trait FrozenWithReturn
{
    public static function bootFrozenWithReturn(): void
    {
        $guard = function (Model $model, string $operation) {
            $status = StatutoryReturn::query()->withoutGlobalScope(AccessScope::class)->whereKey($model->getAttribute('statutory_return_id'))->value('status');

            if ($status !== null && ! in_array($status, StatutoryReturn::EDITABLE, true)) {
                throw new RuntimeException("Cannot {$operation} a line of a {$status} statutory return; create a revision instead.");
            }
        };

        static::updating(fn (Model $m) => $guard($m, 'change'));
        static::deleting(fn (Model $m) => $guard($m, 'delete'));
    }
}
